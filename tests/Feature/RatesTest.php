<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sunrice\Commerce\Cart\CartView;
use Sunrice\Commerce\Checkout\CheckoutData;

/** The rates checkout shows for the input. @return array<int, array<string, mixed>> */
function quotedRates($cart, array $input): array
{
    app()->forgetScopedInstances();

    return CartView::for($cart->fresh(), CheckoutData::fromCart($cart->fresh())->merge($input)->context())->state->shipping['rates'] ?? [];
}

beforeEach(function () {
    $this->method = biteshipStore();
    $this->cart = cartWithMugs(2);
});

it('quotes the chosen couriers by postal code, in grams and centimetres', function () {
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);

    $rates = quotedRates($this->cart, checkoutInput($this->method));

    expect(array_column($rates, 'id'))->toBe(["biteship:{$this->method->id}:jne.reg", "biteship:{$this->method->id}:sicepat.reg"])
        ->and($rates[0]['cost'])->toBe(12000)
        ->and($rates[0]['label'])->toBe('JNE Reguler')
        ->and($rates[0]['eta'])->toBe('2 - 3 days');
    Http::assertSent(function (Request $request) {
        return $request->hasHeader('authorization', 'biteship_test.key')
            && $request['origin_postal_code'] === 40161
            && $request['destination_postal_code'] === 40151   // the village's, from the region list
            && $request['couriers'] === 'jne,sicepat'           // Grab needs coordinates
            && $request['items'] === [['name' => 'Item', 'value' => 50000, 'quantity' => 2, 'weight' => 500, 'length' => 10, 'width' => 8, 'height' => 12]];
    });
});

it('finds the area by district name when there is no postal code', function () {
    Http::fake([
        'api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [['id' => 'IDNP9IDNC22IDND1', 'name' => 'Sukasari, Bandung']]]),
        'api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates()),
    ]);
    $input = checkoutInput($this->method, overrides: ['shipping_address' => ['village_code' => null]]);

    expect(quotedRates($this->cart, $input))->toHaveCount(2);
    quotedRates(cartWithMugs(1), $input);   // the area is remembered

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'maps/areas') && $r['input'] === 'Sukasari, Kota Bandung, Jawa Barat');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rates') && $r['destination_area_id'] === 'IDNP9IDNC22IDND1' && ! isset($r['destination_postal_code']));
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'maps/areas')))->toHaveCount(1);
});

it('leaves instant couriers out until the customer\'s address has coordinates', function () {
    config(['commerce-biteship.origin.latitude' => '-6.86', 'commerce-biteship.origin.longitude' => '107.59']);
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);

    quotedRates($this->cart, checkoutInput($this->method));

    // Commerce addresses have no coordinates yet, so the customer's end is missing.
    Http::assertSent(fn (Request $r) => $r['couriers'] === 'jne,sicepat' && $r['origin_latitude'] === -6.86);
});

it('asks for cash on delivery prices and leaves out couriers without it', function () {
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);

    $rates = quotedRates($this->cart, checkoutInput($this->method, overrides: ['payment_method' => 'cod']));

    expect(array_column($rates, 'id'))->toBe(["biteship:{$this->method->id}:jne.reg"]);
    Http::assertSent(fn (Request $r) => $r['destination_cash_on_delivery'] === 100000 && $r['destination_cash_on_delivery_type'] === '7_days');
});

it('shows no Biteship rates without an API key, and none while Biteship is down', function () {
    config(['commerce-biteship.api_key' => null]);
    Http::fake();
    expect(quotedRates($this->cart, checkoutInput($this->method)))->toBe([]);
    Http::assertNothingSent();

    config(['commerce-biteship.api_key' => 'biteship_test.key']);
    Http::fake(['api.biteship.com/*' => Http::response(['success' => false, 'error' => 'Server error', 'code' => 50000000], 500)]);
    expect(quotedRates(cartWithMugs(3), checkoutInput($this->method)))->toBe([]);
});
