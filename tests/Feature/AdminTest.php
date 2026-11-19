<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Sunrice\Commerce\Commerce;
use Sunrice\Commerce\Health\HealthResult;
use Sunrice\Commerce\Http\Middleware\ScopeAdminOrders;
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

    $this->get("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/label")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $html = app(Labels::class)->html($shipment->fresh());
    expect($html)->toContain('JNE0001')->toContain('BDO-123')->toContain('data:image/svg+xml;base64,')
        ->toContain('Rina')->toContain('Jl. Sarijadi 1')->toContain('Toko Mug')->toContain('Jl. Setiabudi 1')
        ->toContain('COD')->toContain('2 × Mug')->toContain('1,00 kg')
        ->toContain('BITESHIP TESTING MODE');
});

it('answers 404 for an order outside the staff member\'s scope', function () {
    if (! class_exists(ScopeAdminOrders::class)) {
        $this->markTestSkipped('Needs sunrice/commerce with admin order scopes (Commerce::scopeAdminOrders()).');
    }
    $order = paidBiteshipOrder();
    $shipment = $order->shipments()->sole();
    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $this->get("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/label")->assertOk();

    app(Commerce::class)->scopeAdminOrders(fn ($orders) => $orders->where('id', '!=', $order->id));

    $this->get("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/label")->assertNotFound();
    $this->post("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/cancel")->assertNotFound();
    $this->post("/admin/commerce/orders/{$order->id}/biteship/book")->assertNotFound();
});

it('prints Biteship\'s own label when the method asks for it, and the store\'s when Biteship refuses', function () {
    $this->method->update(['settings' => [...$this->method->settings, 'label' => 'biteship', 'label_paper' => 'a4']]);
    $order = paidBiteshipOrder();
    $shipment = $order->shipments()->sole();
    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);
    $url = "/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/label";

    Http::fake(['api.biteship.com/v1/orders/bs_order_1/shipping_labels*' => Http::sequence()
        ->push(['success' => true, 'file_url' => 'https://files.biteship.com/labels/bs_order_1.pdf'])
        // A plan without the Shipping Label API: the store's own label, not an error.
        ->push(['success' => false, 'error' => 'Your plan does not include this feature', 'code' => 40301000], 403)]);

    $this->get($url)->assertRedirect('https://files.biteship.com/labels/bs_order_1.pdf');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/orders/bs_order_1/shipping_labels') && str_contains($r->url(), 'paper_size=a4'));

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('prints the store\'s label by default without asking Biteship', function () {
    $order = paidBiteshipOrder();
    $shipment = $order->shipments()->sole();
    actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);

    $this->get("/admin/commerce/orders/{$order->id}/biteship/{$shipment->id}/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'shipping_labels'));
});

it('gives the order page panel its booking and buttons', function () {
    $order = paidBiteshipOrder();
    $user = actingAsUserWith(['commerce.orders.view', 'commerce.shipments.manage']);

    $panel = collect(app(OrderPanels::class)->for($order, $user))->firstWhere('key', 'biteship');

    expect($panel['data'])->toMatchArray(['testing' => true, 'can_book' => false, 'can_cancel' => true])
        ->and($panel['data']['booking'])->toMatchArray(['waybill' => 'JNE0001', 'order_id' => 'bs_order_1', 'status_label' => 'Dipesan, mencari kurir'])
        ->and($panel['data']['urls']['label'])->toEndWith("/admin/commerce/orders/{$order->id}/biteship/{$order->shipments()->value('id')}/label");
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

it('loads its order panel script after commerce\'s bundle, on commerce\'s pages', function () {
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(public_path('vendor/commerce-biteship')));   // other tests check it isn't published
    $this->artisan('vendor:publish', ['--tag' => 'commerce-biteship-assets', '--force' => true])->assertSuccessful();
    actingAsUserWith(['commerce.orders.view']);

    $this->get('/admin/commerce/orders')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sunrice.bundles', fn ($bundles) => collect($bundles)->contains(fn (array $b) => $b['id'] === 'commerce-biteship'
            && str_contains((string) $b['module'], 'vendor/commerce-biteship/admin.js?v=') && $b['pages'] === [] && $b['extends'] === ['Commerce/'])));
    expect(file_get_contents(public_path('vendor/commerce-biteship/admin.js')))->toContain("registerOrderPanel('biteship'");
});
