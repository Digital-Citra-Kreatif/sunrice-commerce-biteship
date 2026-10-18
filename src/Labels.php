<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Commerce\Documents\DocumentRenderer;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\Commerce\Support\Address;
use Sunrice\Commerce\Support\MoneyFormatter;
use Sunrice\CommerceBiteship\Label\Code128;

/**
 * Shipping labels for Biteship shipments (Biteship has no label API):
 * courier, waybill barcode, routing code, both addresses, weight, COD and
 * contents, on A6 paper.
 */
class Labels
{
    public function __construct(protected DocumentRenderer $renderer, protected StoreSettings $settings) {}

    public function pdf(Shipment $shipment): string
    {
        return $this->renderer->pdf($this->html($shipment), 'a6');
    }

    public function html(Shipment $shipment): string
    {
        $order = $shipment->order;
        $booking = (array) ($shipment->meta['biteship'] ?? []);
        $store = Address::fromArray((array) $this->settings->get('general.address', []));
        $lines = $order->items()->get()->keyBy('id');
        $items = [];
        $weight = 0;
        foreach ((array) $shipment->items as $row) {
            $line = $lines->get($row['order_item_id']);
            if ($line !== null) {
                $items[] = ['name' => trim($line->name.' '.($line->variant_title ?? '')), 'quantity' => (int) $row['quantity']];
                $weight += $line->weight * (int) $row['quantity'];
            }
        }
        $waybill = (string) ($shipment->tracking_number ?? '');

        return view('commerce-biteship::label', [
            'order' => $order,
            'testing' => (bool) ($booking['testing'] ?? false),
            'courier' => $shipment->courier_name ?? strtoupper((string) ($booking['company'] ?? '')),
            'service' => strtoupper((string) ($booking['type'] ?? $shipment->service ?? '')),
            'waybill' => $waybill,
            'barcode' => $waybill !== '' ? Code128::dataUri($waybill) : null,
            'routing' => $booking['routing_code'] ?? null,
            'to' => $order->shippingAddress() ?? Address::fromArray([]),
            'street' => Locations::street($order->shippingAddress() ?? Address::fromArray([]), "\n"),
            'from' => [
                'name' => (string) (config('commerce-biteship.origin.contact_name') ?: $store->get('name') ?: $this->settings->get('general.name') ?: config('app.name')),
                'phone' => (string) (config('commerce-biteship.origin.contact_phone') ?: $store->get('phone') ?: $this->settings->get('general.phone', '')),
                'address' => Locations::street($store),
            ],
            'weight' => $weight,
            'cod' => $order->payment_method === 'cod' && $order->payment_status !== 'paid' ? max(0, $order->total - $order->paid_total) : 0,
            'items' => $items,
            'money' => app(MoneyFormatter::class),
        ])->render();
    }
}
