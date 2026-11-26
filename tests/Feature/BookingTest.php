<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sunrice\Commerce\Orders\Actions\MarkOrderPaid;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Shipping\ShipmentTracker;

beforeEach(function () {
    config(['commerce-biteship.webhook_token' => 'test-token']);
    $this->method = biteshipStore();
});

/** An order for two mugs with JNE REG, placed through checkout. */
function biteshipOrderPlaced(?ShippingMethod $method = null, array $input = []): Order
{
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates())]);

    return placeOrder(cartWithMugs(2), checkoutInput($method ?? test()->method, overrides: $input));
}

it('books the pickup when the order is paid and ships it with the waybill', function () {
    $order = biteshipOrderPlaced();
    expect($order->shipping_method['service'])->toBe('jne.reg');
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);

    app(MarkOrderPaid::class)->handle($order, Actor::system());

    $shipment = $order->shipments()->sole();
    expect($shipment->tracking_number)->toBe('JNE0001')
        ->and($shipment->courier)->toBe('jne')
        ->and($shipment->tracking_url)->toBe('https://track.example/JNE0001')
        ->and($shipment->cost)->toBe(12500)
        ->and($shipment->meta['biteship'])->toMatchArray(['order_id' => 'bs_order_1', 'tracking_id' => 'bs_track_1', 'status' => 'confirmed', 'testing' => true, 'attempt' => 1])
        ->and($order->fresh()->fulfillment_status)->toBe('shipped');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.biteship.com/v1/orders'
        && $r['reference_id'] === $order->number
        && $r['courier_company'] === 'jne' && $r['courier_type'] === 'reg' && $r['delivery_type'] === 'now'
        && $r['origin_postal_code'] === 40161 && $r['destination_postal_code'] === 40151
        && $r['origin_collection_method'] === 'pickup'
        && $r['destination_contact_name'] === 'Rina'
        && ! isset($r['destination_cash_on_delivery'])
        && $r['shipper_organization'] === 'Toko Mug'
        && $r['items'] === [['name' => 'Mug', 'value' => 50000, 'quantity' => 2, 'weight' => 500, 'length' => 10, 'width' => 8, 'height' => 12, 'sku' => 'MUG', 'category' => 'others']]);
    expect($order->events()->pluck('message')->implode("\n"))->toContain('Biteship (JNE) (JNE0001)');
});

it('books cash on delivery orders when they are placed, collecting the total', function () {
    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response(biteshipRates()),
        'api.biteship.com/v1/orders' => Http::response(biteshipOrder()),
    ]);

    $order = placeOrder(cartWithMugs(2), checkoutInput($this->method, overrides: ['payment_method' => 'cod']));

    expect($order->shipments()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/orders') && $r['destination_cash_on_delivery'] === $order->total && $r['destination_cash_on_delivery_type'] === '7_days');
});

it('waits for the order page when booking is manual, and books once', function () {
    $this->method->update(['settings' => [...$this->method->settings, 'booking' => 'manual']]);
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    expect($order->shipments()->count())->toBe(0);

    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertRedirect()->assertSessionHasNoErrors();
    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertSessionHasErrors('biteship');

    expect($order->shipments()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('shows Biteship refusals on the order page and timeline', function () {
    $this->method->update(['settings' => [...$this->method->settings, 'booking' => 'manual']]);
    $order = biteshipOrderPlaced();
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    Http::fake(['api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'Insufficient balance', 'code' => 40002001], 400)]);

    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertSessionHasErrors(['biteship' => 'Biteship: Insufficient balance']);

    expect($order->shipments()->count())->toBe(0)
        ->and($order->events()->pluck('message')->implode("\n"))->toContain('Insufficient balance');
});

it('cancels a booking and books it again on the same shipment', function () {
    $order = biteshipOrderPlaced();
    Http::fakeSequence('api.biteship.com/v1/orders')->push(biteshipOrder())->push(biteshipOrder('confirmed', 'JNE0002', ['id' => 'bs_order_2']));
    Http::fake([
        'api.biteship.com/v1/orders/bs_order_1/cancel' => Http::response(['success' => true, 'status' => 'cancelled']),
        'api.biteship.com/v1/orders/bs_order_1' => Http::response(biteshipOrder('cancelled')),
    ]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    $shipment = $order->shipments()->sole();

    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $this->post("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/cancel")->assertSessionHasNoErrors();
    expect($shipment->fresh()->status)->toBe('cancelled');

    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertSessionHasNoErrors();

    expect($order->shipments()->count())->toBe(1)
        ->and($shipment->fresh())->status->toBe('shipped')->tracking_number->toBe('JNE0002')
        ->and($shipment->fresh()->meta['biteship'])->toMatchArray(['order_id' => 'bs_order_2', 'attempt' => 2]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/orders') && $r['reference_id'] === $order->number.'-2');
});

it('needs the shipments permission', function () {
    $order = biteshipOrderPlaced();
    actingAsUserWith(['commerce.orders.view']);

    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertForbidden();
});

it('follows the booking through webhooks, trusting only the API', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder('confirmed', null))]);
    // The first webhook's body claims delivery; the API says it was just picked up, with the waybill now issued.
    Http::fakeSequence('api.biteship.com/v1/orders/bs_order_1')->push(biteshipOrder('picked', 'JNE0009'))->push(biteshipOrder('delivered', 'JNE0009'));
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    $shipment = $order->shipments()->sole();
    expect($shipment->tracking_number)->toBeNull();

    $this->postJson('/biteship/webhook?token=test-token', ['event' => 'order.status', 'order_id' => 'bs_order_1', 'status' => 'delivered'])->assertOk();
    expect($shipment->fresh())->status->toBe('in_transit')->tracking_number->toBe('JNE0009');

    $this->postJson('/biteship/webhook?token=test-token', ['event' => 'order.status', 'order_id' => 'bs_order_1'])->assertOk();
    expect($shipment->fresh())->status->toBe('delivered')->delivered_at->not->toBeNull();

    // Biteship's test ping and unknown bookings: 200, nothing fetched.
    $sent = count(Http::recorded());
    $this->postJson('/biteship/webhook?token=test-token', [])->assertOk();
    $this->postJson('/biteship/webhook?token=test-token', ['order_id' => 'someone-else'])->assertOk();
    expect(Http::recorded())->toHaveCount($sent);
});

it('tracks booked shipments from commerce:tick', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    Http::fake(['api.biteship.com/v1/orders/bs_order_1' => Http::response(biteshipOrder('delivered', 'JNE0001', ['courier' => ['history' => [
        ['note' => 'Picked up by the courier', 'status' => 'picked', 'updated_at' => '2026-10-18T10:00:00+07:00'],
        ['note' => 'Delivered to Rina', 'status' => 'delivered', 'updated_at' => '2026-10-19T10:00:00+07:00'],
    ]]]))]);

    $info = app(ShipmentTracker::class)->track(Shipment::query()->sole());

    expect($info->delivered)->toBeTrue()
        ->and($info->history[1]['status'])->toBe('Delivered to Rina')
        ->and(Shipment::query()->sole()->status)->toBe('delivered');
});

it('catches a cancellation from commerce:tick when the webhook was missed', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    Http::fake(['api.biteship.com/v1/orders/bs_order_1' => Http::response(biteshipOrder('cancelled', 'JNE0001', ['price' => 15000]))]);

    $info = app(ShipmentTracker::class)->track(Shipment::query()->sole());

    $shipment = Shipment::query()->sole();
    expect($info->delivered)->toBeFalse()
        ->and($shipment->status)->toBe('cancelled')
        ->and((int) $shipment->cost)->toBe(15000)
        ->and($shipment->meta['biteship']['status'])->toBe('cancelled')
        ->and($order->events()->where('type', 'shipment')->where('data->biteship_status', 'cancelled')->exists())->toBeTrue();
});

it('reads statuses written in camelCase as Biteship\'s snake_case', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    Http::fake(['api.biteship.com/v1/orders/bs_order_1' => Http::response(biteshipOrder('droppingOff'))]);

    app(ShipmentTracker::class)->track(Shipment::query()->sole());

    expect(Shipment::query()->sole())->status->toBe('in_transit')
        ->and(Shipment::query()->sole()->meta['biteship']['status'])->toBe('dropping_off');
});

it('takes over a booking Biteship already has for the reference', function () {
    $order = biteshipOrderPlaced();
    Http::fake([
        'api.biteship.com/v1/orders' => Http::response(['success' => false, 'error' => 'Reference id has already been used before', 'code' => 40002060,
            'details' => ['order_id' => 'bs_order_1', 'reference_id' => $order->number]], 400),
        'api.biteship.com/v1/orders/bs_order_1' => Http::response(biteshipOrder()),
    ]);

    app(MarkOrderPaid::class)->handle($order, Actor::system());

    expect($order->shipments()->sole())->tracking_number->toBe('JNE0001')
        ->and($order->shipments()->sole()->meta['biteship']['order_id'])->toBe('bs_order_1');
});

it('cancels only until pickup, and notes a price change at pickup', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);
    Http::fakeSequence('api.biteship.com/v1/orders/bs_order_1')->push(biteshipOrder('picked', 'JNE0001', ['price' => 15000]));
    app(MarkOrderPaid::class)->handle($order, Actor::system());
    $shipment = $order->shipments()->sole();

    $this->postJson('/biteship/webhook?token=test-token', ['event' => 'order.price', 'order_id' => 'bs_order_1'])->assertOk();
    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $this->post("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/cancel")->assertSessionHasErrors('biteship');

    expect($shipment->fresh()->cost)->toBe(15000)
        ->and($order->events()->pluck('message')->implode("\n"))->toContain('Rp12.500')->toContain('Rp15.000');
});

it('asks for the webhook token when one is set', function () {
    config(['commerce-biteship.webhook_token' => 'secret-token']);

    $this->postJson('/biteship/webhook', ['order_id' => 'bs_order_1'])->assertForbidden();
    $this->postJson('/biteship/webhook?token=secret-token', [])->assertOk();
});

it('refuses webhooks entirely when no token is configured', function () {
    config(['commerce-biteship.webhook_token' => null]);

    $this->postJson('/biteship/webhook', ['order_id' => 'bs_order_1'])->assertForbidden();
    $this->postJson('/biteship/webhook?token=anything', ['order_id' => 'bs_order_1'])->assertForbidden();
});

it('refreshes bookings still waiting for a waybill from commerce:tick', function () {
    $order = biteshipOrderPlaced();
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder('confirmed', null))]);
    Http::fakeSequence('api.biteship.com/v1/orders/bs_order_1')->push(biteshipOrder('allocated', 'GRB-77'));
    app(MarkOrderPaid::class)->handle($order, Actor::system());

    $this->travel(2)->hours();
    $this->artisan('commerce:tick')->assertSuccessful();

    expect($order->shipments()->sole()->tracking_number)->toBe('GRB-77');
});

it('hands over the way the courier offers on the route', function () {
    $this->method->update(['settings' => [...$this->method->settings, 'collection' => 'drop_off']]);
    Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [
        [...biteshipRates()['pricing'][0], 'available_collection_method' => ['pickup']],
    ]])]);
    $order = placeOrder(cartWithMugs(2), checkoutInput($this->method));
    Http::fake(['api.biteship.com/v1/orders' => Http::response(biteshipOrder())]);

    app(MarkOrderPaid::class)->handle($order, Actor::system());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/orders') && $r['origin_collection_method'] === 'pickup');
});
