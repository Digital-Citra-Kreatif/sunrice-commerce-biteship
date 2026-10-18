<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\Models\OrderItem;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\Commerce\Shipping\BookingResult;
use Sunrice\Commerce\Shipping\Contracts\BooksShipments;
use Sunrice\Commerce\Shipping\Contracts\ShippingMethodType;
use Sunrice\Commerce\Shipping\Contracts\TracksShipments;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Shipping\Package;
use Sunrice\Commerce\Shipping\ShippingRate;
use Sunrice\Commerce\Shipping\ShippingRequest;
use Sunrice\Commerce\Shipping\TrackingInfo;
use Sunrice\Commerce\Support\Address;

/**
 * Live rates from the couriers chosen on the method, booking the pickup
 * (Biteship's Order API) and tracking. A rate's service is
 * "{courier}.{service}", e.g. "jne.reg" or "grab.instant".
 */
class BiteshipShipping implements BooksShipments, ShippingMethodType, TracksShipments
{
    /** Biteship courier codes and names. */
    public const COURIERS = [
        'jne' => 'JNE', 'jnt' => 'J&T Express', 'sicepat' => 'SiCepat', 'anteraja' => 'AnterAja', 'pos' => 'POS Indonesia',
        'tiki' => 'TIKI', 'ninja' => 'Ninja Xpress', 'lion' => 'Lion Parcel', 'idexpress' => 'ID Express', 'sap' => 'SAP Express',
        'jdl' => 'JDL Express', 'rpx' => 'RPX', 'wahana' => 'Wahana', 'sentralcargo' => 'Sentral Cargo', 'jntcargo' => 'J&T Cargo',
        'gojek' => 'GoSend', 'grab' => 'GrabExpress', 'lalamove' => 'Lalamove', 'borzo' => 'Borzo', 'paxel' => 'Paxel',
    ];

    /** Couriers that price by distance: both ends need coordinates. */
    public const INSTANT = ['gojek', 'grab', 'lalamove', 'borzo', 'paxel'];

    public const DEFAULT_COURIERS = ['jne', 'jnt', 'sicepat', 'anteraja', 'pos'];

    public function __construct(protected Client $client, protected Locations $locations, protected StoreSettings $settings) {}

    public static function key(): string
    {
        return 'biteship';
    }

    public function label(): string
    {
        return 'Biteship (live courier rates)';
    }

    public function settingsSchema(): array
    {
        return [
            ['handle' => 'couriers', 'type' => 'select', 'label' => 'Couriers', 'instructions' => 'GoSend, GrabExpress, Lalamove, Borzo and Paxel need the coordinates of the store and the customer.',
                'config' => ['multiple' => true, 'options' => array_map(fn ($code, $name) => ['value' => $code, 'label' => $name], array_keys(self::COURIERS), self::COURIERS)]],
            ['handle' => 'booking', 'type' => 'select', 'label' => 'Book the pickup', 'config' => ['options' => [
                ['value' => 'manual', 'label' => 'From the order page'],
                ['value' => 'paid', 'label' => 'When the order is paid (cash on delivery: when it is placed)'],
            ]]],
            ['handle' => 'collection', 'type' => 'select', 'label' => 'Hand over', 'config' => ['options' => [
                ['value' => 'pickup', 'label' => 'The courier picks up at the store'],
                ['value' => 'drop_off', 'label' => 'I drop parcels off at the courier'],
            ]]],
            ['handle' => 'insurance', 'type' => 'toggle', 'label' => 'Insure parcels for their value'],
        ];
    }

    public function settingsRules(): array
    {
        return [
            'couriers' => ['nullable', 'array'],
            'couriers.*' => ['string', 'in:'.implode(',', array_keys(self::COURIERS))],
            'booking' => ['nullable', 'in:manual,paid'],
            'collection' => ['nullable', 'in:pickup,drop_off'],
        ];
    }

    public function rates(ShippingRequest $request, ShippingMethod $method): array
    {
        if ($request->destination === null || ! $request->destination->isIndonesian() || $request->package->lines === [] || ! $this->client->configured()) {
            return [];
        }
        $origin = $this->locations->origin($request->origin);
        $destination = $this->locations->locate($request->destination);
        $couriers = self::couriers($method, Locations::hasCoordinates($origin) && Locations::hasCoordinates($destination));
        if ($couriers === [] || ($destination['postal_code'] ?? $destination['area_id']) === null && ! Locations::hasCoordinates($destination)) {
            return [];
        }
        $body = [
            ...Locations::rateFields('origin', $origin),
            ...Locations::rateFields('destination', $destination),
            'couriers' => implode(',', $couriers),
            'items' => self::items($request->package),
            ...($method->setting('insurance') ? ['courier_insurance' => $request->subtotal] : []),
            ...($request->cod ? ['destination_cash_on_delivery' => $request->subtotal, 'destination_cash_on_delivery_type' => '7_days'] : []),
        ];
        $rates = [];
        foreach ((array) ($this->client->rates($body)['pricing'] ?? []) as $price) {
            if ($request->cod && ($price['available_for_cash_on_delivery'] ?? true) === false) {
                continue;
            }
            $courier = (string) ($price['courier_code'] ?? $price['company'] ?? '');
            $service = (string) ($price['courier_service_code'] ?? $price['type'] ?? '');
            if ($courier === '' || $service === '') {
                continue;
            }
            $rates[] = ShippingRate::for($method, (int) ($price['price'] ?? 0), "{$courier}.{$service}",
                trim(($price['courier_name'] ?? self::COURIERS[$courier] ?? $courier).' '.($price['courier_service_name'] ?? $service)),
                self::duration($price), ['courier' => $courier, 'courier_service' => $service, 'description' => $price['description'] ?? null]);
        }

        return $rates;
    }

    /** Books the order's chosen service; the order number is the reference ("-2" on a second booking), so Biteship refuses a duplicate. */
    public function book(Order $order, Shipment $shipment): BookingResult
    {
        $service = (string) ($order->shipping_method['service'] ?? '');
        [$courier, $type] = array_pad(explode('.', $service, 2), 2, '');
        $method = ShippingMethod::query()->find($order->shipping_method['method_id'] ?? null);
        $store = Address::fromArray((array) $this->settings->get('general.address', []));
        $to = $order->shippingAddress();
        if ($courier === '' || $type === '' || $to === null) {
            throw new BiteshipException('The order has no Biteship service or shipping address.');
        }
        $items = $this->orderItems($order, $shipment);
        $cod = $order->payment_method === 'cod' && $order->payment_status !== 'paid';
        $attempt = (int) ($shipment->meta['biteship']['attempt'] ?? 1);
        $response = $this->client->createOrder([
            'reference_id' => $order->number.($attempt > 1 ? "-{$attempt}" : ''),
            'shipper_contact_name' => (string) ($this->settings->get('general.name') ?: config('app.name')),
            'shipper_contact_phone' => (string) $this->settings->get('general.phone', ''),
            'shipper_contact_email' => (string) $this->settings->get('general.email', ''),
            'origin_contact_name' => (string) (config('commerce-biteship.origin.contact_name') ?: $store->get('name') ?: $this->settings->get('general.name') ?: config('app.name')),
            'origin_contact_phone' => (string) (config('commerce-biteship.origin.contact_phone') ?: $store->get('phone') ?: $this->settings->get('general.phone', '')),
            'origin_address' => Locations::street($store),
            'origin_note' => (string) config('commerce-biteship.origin.note', ''),
            ...Locations::orderFields('origin', $this->locations->origin($store)),
            'origin_collection_method' => (string) ($method?->setting('collection') ?: 'pickup'),
            'destination_contact_name' => (string) ($to->get('name') ?: $order->customer_name),
            'destination_contact_phone' => (string) ($to->get('phone') ?: $order->phone),
            'destination_contact_email' => (string) $order->email,
            'destination_address' => Locations::street($to),
            'destination_note' => (string) $to->get('notes'),
            ...Locations::orderFields('destination', $this->locations->locate($to)),
            'courier_company' => $courier,
            'courier_type' => $type,
            'delivery_type' => 'now',
            ...($method?->setting('insurance') ? ['courier_insurance' => array_sum(array_map(fn ($i) => $i['value'] * $i['quantity'], $items))] : []),
            ...($cod ? ['destination_cash_on_delivery' => $order->total - $order->paid_total, 'destination_cash_on_delivery_type' => '7_days'] : []),
            'order_note' => (string) $order->customer_note,
            'metadata' => ['order_id' => $order->id, 'shipment_id' => $shipment->id],
            'items' => $items,
        ]);
        $waybill = (string) ($response['courier']['waybill_id'] ?? '');

        return new BookingResult($waybill, $response['courier']['link'] ?? null, Bookings::meta($response));
    }

    public function track(Shipment $shipment): TrackingInfo
    {
        $id = (string) ($shipment->meta['biteship']['tracking_id'] ?? '');
        if ($id === '') {
            return new TrackingInfo((string) ($shipment->meta['biteship']['status'] ?? 'unknown'));
        }
        $tracking = $this->client->tracking($id);
        $status = (string) ($tracking['status'] ?? 'unknown');

        return new TrackingInfo(Bookings::statusLabel($status), $status === 'delivered', array_map(fn (array $h) => [
            'at' => (string) ($h['updated_at'] ?? ''),
            'status' => (string) ($h['note'] ?? Bookings::statusLabel((string) ($h['status'] ?? ''))),
        ], array_values(array_filter((array) ($tracking['history'] ?? []), 'is_array'))));
    }

    /** @return array<int, string> the method's couriers, without instant ones when coordinates are missing */
    public static function couriers(ShippingMethod $method, bool $coordinates): array
    {
        $chosen = array_values(array_intersect((array) ($method->setting('couriers') ?: self::DEFAULT_COURIERS), array_keys(self::COURIERS)));

        return $coordinates ? $chosen : array_values(array_diff($chosen, self::INSTANT));
    }

    /** @return array<int, array{name: string, value: int, quantity: int, weight: int, length: int, width: int, height: int}> */
    public static function items(Package $package): array
    {
        return array_map(fn (array $l) => self::item('Item', intdiv($l['value'], max(1, $l['quantity'])), $l['quantity'], $l['weight'], $l['length'], $l['width'], $l['height']), $package->lines);
    }

    /** @return array{name: string, value: int, quantity: int, weight: int, length: int, width: int, height: int} weight in grams, sizes from mm to cm */
    public static function item(string $name, int $value, int $quantity, int $weight, int $length, int $width, int $height): array
    {
        $cm = fn (int $mm) => max(1, (int) ceil($mm / 10));

        return ['name' => mb_substr($name, 0, 100) ?: 'Item', 'value' => max(0, $value), 'quantity' => max(1, $quantity), 'weight' => max(1, $weight),
            'length' => $cm($length), 'width' => $cm($width), 'height' => $cm($height)];
    }

    /** @return array<int, array<string, mixed>> the shipment's items with their names, values and sizes */
    protected function orderItems(Order $order, Shipment $shipment): array
    {
        $lines = $order->items()->with('variant')->get()->keyBy('id');
        $items = [];
        foreach ((array) $shipment->items as $row) {
            $line = $lines->get($row['order_item_id']);
            if ($line === null) {
                continue;
            }
            /** @var OrderItem $line */
            $variant = $line->variant;   // null when the product was deleted since
            $items[] = self::item($line->name, $line->unit_price, (int) $row['quantity'],
                $line->weight, (int) $variant?->length, (int) $variant?->width, (int) $variant?->height);
        }

        return $items;
    }

    /** @param  array<string, mixed>  $price */
    protected static function duration(array $price): ?string
    {
        $range = trim((string) ($price['shipment_duration_range'] ?? ''));
        $unit = (string) ($price['shipment_duration_unit'] ?? 'days');

        return $range === '' ? (($price['duration'] ?? null) ?: null) : "{$range} {$unit}";
    }
}
