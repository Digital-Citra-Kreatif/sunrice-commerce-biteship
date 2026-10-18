<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Sunrice\Commerce\Facades\Commerce;
use Sunrice\Commerce\Features\Feature;
use Sunrice\Commerce\Orders\Events\OrderPaid;
use Sunrice\Commerce\Orders\Events\OrderPlaced;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Support\MoneyFormatter;
use Sunrice\CommerceBiteship\Listeners\BookWhenReady;
use Sunrice\Facades\Sunrice;

class BiteshipServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('commerce-biteship')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Client::class);
    }

    public function packageBooted(): void
    {
        Commerce::registerFeature(new Feature('biteship', 'shipping', 'Biteship', 'Live rates from Indonesian couriers, pickup booking, tracking and shipping labels.', ['shipping']));
        Commerce::registerShippingMethodType(BiteshipShipping::class);
        Commerce::registerHealthCheck(BiteshipHealthCheck::class);
        Commerce::registerOrderPanel('biteship', 'Biteship', fn (Order $order): array => $this->panel($order), 'commerce.shipments.manage', 40);
        foreach (['tiki' => 'TIKI', 'sap' => 'SAP Express', 'jdl' => 'JDL Express', 'lalamove' => 'Lalamove', 'borzo' => 'Borzo', 'paxel' => 'Paxel'] as $key => $name) {
            Commerce::registerCourier($key, $name);
        }

        Event::listen(OrderPaid::class, BookWhenReady::class);
        Event::listen(OrderPlaced::class, BookWhenReady::class);

        Sunrice::registerAdminScript('vendor/commerce-biteship/admin.js');
        $this->publishes([__DIR__.'/../resources/dist' => public_path('vendor/commerce-biteship')], 'commerce-biteship-assets');

        if (! $this->app->routesAreCached()) {
            Route::group([], __DIR__.'/../routes/webhooks.php');
            Sunrice::adminRoutes(function (): void {
                require __DIR__.'/../routes/admin.php';
            });
        }
    }

    /** @return array<string, mixed> the order page's Biteship panel (empty: not shipped with Biteship) */
    protected function panel(Order $order): array
    {
        if (! commerce_feature('biteship') || ($order->shipping_method['method'] ?? null) !== BiteshipShipping::key()) {
            return [];
        }
        $shipment = app(Bookings::class)->shipmentFor($order);
        $booking = (array) ($shipment?->meta['biteship'] ?? []);
        $active = Bookings::active($shipment) && ! in_array($shipment?->status, ['delivered', 'returned'], true);
        $processing = in_array($order->status, ['processing', 'completed', 'on_hold'], true);
        $unshipped = $order->items()->whereNull('parent_id')->where('requires_shipping', true)->get()->contains(fn ($i) => $i->unfulfilled() > 0);
        $driver = array_filter([$booking['driver']['name'] ?? null, $booking['driver']['phone'] ?? null, $booking['driver']['plate'] ?? null]);
        $route = fn (string $name) => $shipment === null ? null : route("sunrice.admin.commerce-biteship.{$name}", ['order' => $order->id, 'shipment' => $shipment->id]);

        return [
            'service' => $order->shipping_method['label'] ?? null,
            'testing' => app(Client::class)->testing(),
            'booking' => $shipment === null ? null : [
                'order_id' => $booking['order_id'] ?? null,
                'status_label' => Bookings::statusLabel((string) ($booking['status'] ?? '')),
                'courier' => trim(($shipment->courier_name ?? '').' '.strtoupper((string) ($booking['type'] ?? ''))),
                'waybill' => $shipment->tracking_number,
                'link' => $booking['link'] ?? $shipment->tracking_url,
                'driver' => $driver === [] ? null : implode(' · ', $driver),
                'price' => isset($booking['price']) ? app(MoneyFormatter::class)->format((int) $booking['price']) : null,
            ],
            'can_book' => $processing && ! $active && ($unshipped || $shipment?->status === 'cancelled'),
            'can_cancel' => $active,
            'urls' => [
                'book' => route('sunrice.admin.commerce-biteship.book', ['order' => $order->id]),
                'cancel' => $route('cancel'),
                'refresh' => $route('refresh'),
                'label' => $shipment !== null && $shipment->status !== 'cancelled' ? $route('label') : null,
            ],
        ];
    }
}
