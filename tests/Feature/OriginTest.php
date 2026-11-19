<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sunrice\Commerce\Commerce;
use Sunrice\Commerce\Orders\Actions\MarkOrderPaid;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Shipping\ShippingOrigins;
use Sunrice\CommerceBiteship\Labels;

/** A cart an extension ships from a branch (commerce's shipping origins): its own postal code and map point, not the store's. */
beforeEach(function () {
    if (! class_exists(ShippingOrigins::class)) {
        $this->markTestSkipped('Needs sunrice/commerce with shipping origins (Commerce::shippingOriginUsing()).');
    }
    $this->method = biteshipStore();
    app(Commerce::class)->shippingOriginUsing(fn () => ['name' => 'Cabang Dago', 'phone' => '0811000222', 'line1' => 'Jl. Dago 10', 'postal_code' => '40135',
        'country' => 'ID', 'latitude' => '-6.885', 'longitude' => '107.613']);
});

it('quotes from the branch', function () {
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);

    placeOrder(cartWithMugs(1), checkoutInput($this->method));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rates/couriers')
        && $r['origin_postal_code'] === 40135 && $r['origin_latitude'] === -6.885 && $r['origin_longitude'] === 107.613);
});

it('books the pickup at the branch, with its contact', function () {
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);
    $order = placeOrder(cartWithMugs(2), checkoutInput($this->method));
    expect($order->meta('shipping_origin.postal_code'))->toBe('40135');
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);

    app(MarkOrderPaid::class)->handle($order, Actor::system());

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.biteship.com/v1/orders'
        && $r['origin_postal_code'] === 40135 && $r['origin_address'] === 'Jl. Dago 10, 40135, Indonesia'
        && $r['origin_contact_name'] === 'Cabang Dago' && $r['origin_contact_phone'] === '0811000222'
        && $r['origin_coordinate'] === ['latitude' => -6.885, 'longitude' => 107.613]);
});

it('prints the branch as the sender on the label', function () {
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);
    $order = placeOrder(cartWithMugs(1), checkoutInput($this->method));
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());

    $html = app(Labels::class)->html($order->shipments()->sole());

    expect($html)->toContain('Cabang Dago')->toContain('0811000222')->toContain('Jl. Dago 10')
        ->not->toContain('Jl. Setiabudi 1');
});
