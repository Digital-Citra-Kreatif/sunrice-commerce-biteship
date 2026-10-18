<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sunrice\Commerce\Health\HealthResult;
use Sunrice\Commerce\Orders\Actions\MarkOrderPaid;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\OrderPanels;
use Sunrice\CommerceBiteship\BiteshipHealthCheck;
use Sunrice\CommerceBiteship\Labels;

beforeEach(function () {
    $this->method = biteshipStore();
    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates()),
        'api.biteship.com/v1/orders' => Http::response(biteshipOrder()),
    ]);
});

function paidBiteshipOrder(array $input = []): Order
{
    $order = placeOrder(cartWithMugs(2), checkoutInput(test()->method, overrides: $input));
    if ($order->payment_method !== 'cod') {
        app(MarkOrderPaid::class)->handle($order, Actor::system());
    }

    return $order->fresh();
}

it('prints a shipping label with the waybill, both addresses and the COD amount', function () {
    $order = paidBiteshipOrder(['payment_method' => 'cod']);
    $shipment = $order->shipments()->sole();
    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);

    $this->get("/cms/commerce/orders/{$order->id}/biteship/{$shipment->id}/label")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $html = app(Labels::class)->html($shipment->fresh());
    expect($html)->toContain('JNE0001')->toContain('BDO-123')->toContain('data:image/svg+xml;base64,')
        ->toContain('Rina')->toContain('Jl. Sarijadi 1')->toContain('Toko Mug')->toContain('Jl. Setiabudi 1')
        ->toContain('COD')->toContain('2 × Mug')->toContain('1,00 kg')
        ->toContain('BITESHIP TESTING MODE');
});

it('gives the order page panel its booking and buttons', function () {
    $order = paidBiteshipOrder();
    $user = actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);

    $panel = collect(app(OrderPanels::class)->for($order, $user))->firstWhere('key', 'biteship');

    expect($panel['data'])->toMatchArray(['testing' => true, 'can_book' => false, 'can_cancel' => true])
        ->and($panel['data']['booking'])->toMatchArray(['waybill' => 'JNE0001', 'order_id' => 'bs_order_1', 'status_label' => 'Dipesan, mencari kurir'])
        ->and($panel['data']['urls']['label'])->toEndWith("/cms/commerce/orders/{$order->id}/biteship/{$order->shipments()->value('id')}/label");
});

it('checks the key, the origin and Testing Mode in production', function () {
    $check = app(BiteshipHealthCheck::class);
    config(['commerce-biteship.api_key' => null]);
    expect($check->run()->status)->toBe(HealthResult::ERROR);

    config(['commerce-biteship.api_key' => 'biteship_test.key']);
    app()->detectEnvironment(fn () => 'production');
    expect($check->run())->status->toBe(HealthResult::WARNING)->message->toContain('Testing Mode');

    config(['commerce-biteship.api_key' => 'biteship_live.key']);
    expect($check->run())->status->toBe(HealthResult::WARNING)->message->toContain('published');
});
