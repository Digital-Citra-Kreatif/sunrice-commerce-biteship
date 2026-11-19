<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Commerce\Documents\DocumentRenderer;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\Commerce\Support\Address;
use Sunrice\Commerce\Support\MoneyFormatter;
use Sunrice\CommerceBiteship\Label\Code128;

/**
 * Shipping labels for Biteship shipments, drawn by the store (or Biteship's own,
 * see biteshipUrl()):
 * courier, waybill barcode, routing code, both addresses, weight, COD and
 * contents, on A6 paper. The sender is where the pickup was booked: the
 * order's branch when an extension chose one, else the store.
 */
class Labels
{
    public function __construct(protected DocumentRenderer $renderer, protected StoreSettings $settings, protected Client $client) {}

    /**
     * Biteship's own label for the booking, when the order's method asks for it ("Shipping label:
     * Biteship's"): its PDF's URL, or null for the store's label (the method doesn't ask for it,
     * or Biteship refused, e.g. a plan without the Shipping Label API: reported, not thrown).
     */
    public function biteshipUrl(Shipment $shipment): ?string
    {
        $method = ShippingMethod::query()->find($shipment->order->shipping_method['method_id'] ?? null);
        $id = (string) ($shipment->meta['biteship']['order_id'] ?? '');
        if ($method?->setting('label') !== 'biteship' || $id === '') {
            return null;
        }
        try {
            return $this->client->shippingLabel($id, array_filter(['paper_size' => $method->setting('label_paper')]));
        } catch (BiteshipException $e) {
            report($e);

            return null;
        }
    }

    public function pdf(Shipment $shipment): string
    {
        return $this->renderer->pdf($this->html($shipment), 'a6');
    }

    public function html(Shipment $shipment): string
    {
        $order = $shipment->order;
        $booking = (array) ($shipment->meta['biteship'] ?? []);
        // Where the courier picks up, as booked: a branch an extension chose (meta.shipping_origin), else the store.
        $origin = $order->meta('shipping_origin');
        $branch = is_array($origin) && $origin !== [];
        $store = Address::fromArray($branch ? $origin : (array) $this->settings->get('general.address', []));
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
                'name' => (string) (($branch ? $store->get('name') : null) ?: config('commerce-biteship.origin.contact_name') ?: $store->get('name') ?: $this->settings->get('general.name') ?: config('app.name')),
                'phone' => (string) (($branch ? $store->get('phone') : null) ?: config('commerce-biteship.origin.contact_phone') ?: $store->get('phone') ?: $this->settings->get('general.phone', '')),
                'address' => Locations::street($store),
            ],
            'weight' => $weight,
            'cod' => $order->payment_method === 'cod' && $order->payment_status !== 'paid' ? max(0, $order->total - $order->paid_total) : 0,
            'items' => $items,
            'money' => app(MoneyFormatter::class),
        ])->render();
    }
}
