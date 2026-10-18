<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Sunrice\Actions\Entries\PublishTranslation;
use Sunrice\Actions\Entries\SaveDraft;
use Sunrice\Commerce\Cart\CartView;
use Sunrice\Commerce\Cart\Models\Cart;
use Sunrice\Commerce\Catalog\Actions\ScaffoldStore;
use Sunrice\Commerce\Catalog\Models\Variant;
use Sunrice\Commerce\Checkout\CheckoutData;
use Sunrice\Commerce\Commerce;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Regions\RegionImporter;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Shipping\Models\ShippingZone;
use Sunrice\CommerceBiteship\Tests\TestCase;
use Sunrice\Models\Blueprint;
use Sunrice\Models\Collection;
use Sunrice\Models\Entry;
use Sunrice\Support\Locales;
use Workbench\App\Commerce\TestTransfer;
use Workbench\App\Models\User;

uses(TestCase::class)->in('Feature', 'Unit');

/** Sign in to the admin as a user whose role has these permissions (plus sunrice.access-admin). */
function actingAsUserWith(array $permissions, string $email = 'staff@example.com'): User
{
    test()->artisan('sunrice:sync-permissions');
    $role = Role::findOrCreate('Role '.md5($email), config('sunrice.auth.guard', 'web'));
    $role->givePermissionTo(['sunrice.access-admin', ...$permissions]);
    $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password' => bcrypt('password')]);
    $user->assignRole($role);
    test()->actingAs($user, config('sunrice.auth.guard', 'web'));

    return $user;
}

/**
 * A store in Bandung (postal code 40161) selling a "Mug" (50.000, 500 g,
 * 10 × 8 × 12 cm), shipping with one Biteship method and paid by test
 * transfer or cash on delivery. Returns the Biteship method.
 *
 * @param  array<string, mixed>  $settings  the method's settings
 */
function biteshipStore(array $settings = []): ShippingMethod
{
    (new RegionImporter(__DIR__.'/Fixtures/regions'))->import(4);
    app(ScaffoldStore::class)->handle();
    $collection = Collection::query()->where('handle', 'products')->first() ?? Collection::query()->create(['handle' => 'products', 'title' => 'Products', 'settings' => ['route' => 'products/{slug}'],
        'blueprint_id' => Blueprint::query()->create(['handle' => 'product', 'title' => 'Product', 'fields' => [['handle' => 'product', 'type' => 'commerce_product', 'label' => 'Product']]])->id]);
    $entry = Entry::query()->create(['collection_id' => $collection->id, 'status' => 'draft', 'sort_order' => 0]);
    $translation = $entry->translations()->create(['locale' => Locales::main(), 'collection_id' => $collection->id, 'title' => 'Mug', 'slug' => 'mug', 'data' => [], 'seo' => []]);
    app(SaveDraft::class)->handle($translation, ['title' => 'Mug', 'slug' => 'mug', 'data' => ['product' => ['variants' => [
        ['id' => 'mug', 'sku' => 'MUG', 'price' => 50000, 'weight' => 500, 'length' => 100, 'width' => 80, 'height' => 120, 'track_stock' => true, 'initial_stock' => 5],
    ]]]]);
    app(PublishTranslation::class)->handle($translation->fresh());

    $zone = ShippingZone::query()->create(['name' => 'Indonesia']);
    $zone->regions()->create(['country' => 'ID']);
    $method = $zone->methods()->create(['type' => 'biteship', 'title' => ['id' => 'Kurir', 'en' => 'Courier'], 'active' => true,
        'settings' => ['couriers' => ['jne', 'sicepat', 'grab'], 'booking' => 'paid', 'collection' => 'pickup', ...$settings]]);
    app(Commerce::class)->registerPaymentMethod(TestTransfer::class);
    $settings = app(StoreSettings::class);
    $settings->saveSection('general', ['name' => 'Toko Mug', 'phone' => '0811 1111 1111', 'email' => 'toko@example.com', 'address' => [
        'line1' => 'Jl. Setiabudi 1', 'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40161', 'country' => 'ID', 'phone' => '0811 1111 1111',
    ]]);
    $settings->saveSection('payments', ['methods' => [
        'test_transfer' => ['active' => true, 'title' => ['id' => 'Transfer', 'en' => 'Transfer'], 'deadline_hours' => 24],
        'cod' => ['active' => true, 'title' => ['id' => 'COD', 'en' => 'COD']],
    ]]);
    app()->forgetScopedInstances();

    return $method->fresh();
}

function mug(): Variant
{
    return Variant::query()->where('sku', 'MUG')->firstOrFail();
}

/** Checkout input for a Sarijadi (Bandung, 40151) address. @return array<string, mixed> */
function checkoutInput(ShippingMethod $method, string $service = 'jne.reg', array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Rina', 'email' => 'rina@example.com', 'phone' => '0812 3456 7890',
        'shipping_address' => ['name' => 'Rina', 'phone' => '0812 3456 7890', 'country' => 'ID', 'province_code' => '32', 'city_code' => '32.73',
            'district_code' => '32.73.01', 'village_code' => '32.73.01.1001', 'line1' => 'Jl. Sarijadi 1'],
        'billing_same' => '1',
        'shipping_rate' => "biteship:{$method->id}:{$service}",
        'payment_method' => 'test_transfer',
    ], $overrides);
}

/** A guest cart with mugs, sent as the visitor's cookie. */
function cartWithMugs(int $quantity = 2): Cart
{
    $cart = Cart::query()->create(['token' => Str::random(64), 'status' => 'active', 'currency' => 'IDR', 'last_activity_at' => now()]);
    $cart->items()->create(['variant_id' => mug()->id, 'quantity' => $quantity]);
    test()->withCookie((string) config('commerce.cart.cookie', 'commerce_cart'), $cart->token);

    return $cart;
}

/** Places the order through POST /checkout. */
function placeOrder(Cart $cart, array $input): Order
{
    app()->forgetScopedInstances();
    $hash = CartView::for($cart->fresh(), CheckoutData::fromCart($cart->fresh())->merge($input)->context())->state->hash();
    test()->post('/checkout', [...$input, 'action' => 'place', 'totals_hash' => $hash])->assertSessionHasNoErrors();

    return Order::query()->latest('id')->firstOrFail();
}

/** Biteship's rates response with JNE REG, SiCepat REG (no COD) and Grab Instant. @return array<string, mixed> */
function biteshipRates(): array
{
    return ['success' => true, 'pricing' => [
        ['courier_name' => 'JNE', 'courier_code' => 'jne', 'courier_service_name' => 'Reguler', 'courier_service_code' => 'reg', 'shipment_duration_range' => '2 - 3', 'shipment_duration_unit' => 'days', 'price' => 12000, 'available_for_cash_on_delivery' => true],
        ['courier_name' => 'SiCepat', 'courier_code' => 'sicepat', 'courier_service_name' => 'Reguler', 'courier_service_code' => 'reg', 'shipment_duration_range' => '1 - 2', 'shipment_duration_unit' => 'days', 'price' => 11000, 'available_for_cash_on_delivery' => false],
    ]];
}

/** A Biteship order as the API returns it. @return array<string, mixed> */
function biteshipOrder(string $status = 'confirmed', ?string $waybill = 'JNE0001', array $overrides = []): array
{
    return array_replace_recursive(['success' => true, 'id' => 'bs_order_1', 'status' => $status, 'price' => 12500,
        'courier' => ['tracking_id' => 'bs_track_1', 'waybill_id' => $waybill, 'company' => 'jne', 'type' => 'reg', 'link' => 'https://track.example/JNE0001', 'routing_code' => 'BDO-123'],
    ], $overrides);
}
