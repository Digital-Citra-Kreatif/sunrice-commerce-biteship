<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Commerce\Orders\Actions\UpdateShipment;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Orders\Timeline;
use Sunrice\Commerce\Support\MoneyFormatter;

/**
 * Biteship bookings live on commerce shipments, in meta.biteship (order
 * id, tracking id, waybill, status, driver). A booking's status moves the
 * shipment: picked up → in transit, delivered → delivered, cancelled or no
 * courier → cancelled (book again from the order page).
 */
class Bookings
{
    /** Booking statuses that end it without delivery: the order can be booked again. */
    public const ENDED = ['cancelled', 'rejected', 'courier_not_found', 'disposed'];

    protected const IN_TRANSIT = ['picked', 'in_transit', 'dropping_off', 'on_hold', 'return_in_transit'];

    /** Biteship cancels a booking only until the courier has picked the parcel up. */
    public const CANCELLABLE = ['confirmed', 'scheduled', 'allocated', 'picking_up'];

    public function __construct(protected Timeline $timeline, protected UpdateShipment $update) {}

    /** The order's latest Biteship shipment. */
    public function shipmentFor(Order $order): ?Shipment
    {
        return $order->shipments()->latest('id')->get()->first(fn (Shipment $s) => isset($s->meta['biteship']['order_id']));
    }

    public static function active(?Shipment $shipment): bool
    {
        return $shipment !== null && isset($shipment->meta['biteship']['order_id']) && ! in_array($shipment->meta['biteship']['status'] ?? '', self::ENDED, true);
    }

    public static function cancellable(?Shipment $shipment): bool
    {
        return self::active($shipment) && in_array($shipment?->meta['biteship']['status'] ?? '', self::CANCELLABLE, true);
    }

    /**
     * What to keep of a Biteship order (from creating or fetching it).
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function meta(array $order): array
    {
        $courier = (array) ($order['courier'] ?? []);

        return array_filter([
            'order_id' => $order['id'] ?? null,
            'tracking_id' => $courier['tracking_id'] ?? null,
            'waybill_id' => $courier['waybill_id'] ?? null,
            'company' => $courier['company'] ?? null,
            'type' => $courier['type'] ?? null,
            'link' => $courier['link'] ?? null,
            'routing_code' => $courier['routing_code'] ?? null,
            'driver' => array_filter([
                'name' => $courier['driver_name'] ?? null, 'phone' => $courier['driver_phone'] ?? null,
                'plate' => $courier['driver_plate_number'] ?? null, 'photo' => $courier['driver_photo_url'] ?? null,
            ]) ?: null,
            'price' => isset($order['price']) ? (int) $order['price'] : null,
            'status' => $order['status'] ?? null,
            'testing' => app(Client::class)->testing() ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The tick step: bookings still waiting for a waybill (commerce's own
     * tracking needs one), refreshed at most hourly, in case a webhook was missed.
     */
    public function refreshPending(Client $client): int
    {
        $refreshed = 0;
        $pending = Shipment::query()->with('order')->whereIn('status', ['shipped', 'in_transit'])->whereNull('tracking_number')
            ->where('shipped_at', '>=', now()->subDays(14))->whereNotNull('meta->biteship->order_id')->orderBy('id')->limit(100)->get()
            ->filter(fn (Shipment $s) => ($at = $s->meta['biteship']['synced_at'] ?? $s->meta['biteship']['booked_at'] ?? null) === null || now()->diffInMinutes($at, true) >= 60)
            ->take(20);
        foreach ($pending as $shipment) {
            try {
                $this->apply($shipment, $client->order((string) $shipment->meta['biteship']['order_id']));
                $refreshed++;
            } catch (BiteshipException $e) {
                report($e);
            }
        }

        return $refreshed;
    }

    public static function statusLabel(string $status): string
    {
        $key = 'commerce-biteship::biteship.status.'.$status;

        return __($key) === $key ? ucfirst(str_replace('_', ' ', $status)) : __($key);
    }

    /**
     * Brings the shipment up to date with the Biteship order (fetched from
     * the API, never taken from a webhook body).
     *
     * @param  array<string, mixed>  $order
     */
    public function apply(Shipment $shipment, array $order): Shipment
    {
        $before = (array) ($shipment->meta['biteship'] ?? []);
        $now = [...$before, ...self::meta($order), 'synced_at' => now()->toIso8601String()];
        $status = (string) ($now['status'] ?? '');
        $waybill = trim((string) ($now['waybill_id'] ?? ''));
        $shipment->forceFill([
            'tracking_number' => $waybill !== '' ? $waybill : $shipment->tracking_number,
            'tracking_url' => $now['link'] ?? $shipment->tracking_url,
            'cost' => $now['price'] ?? $shipment->cost,
            'status' => match (true) {
                in_array($shipment->status, ['delivered', 'returned'], true) => $shipment->status,
                in_array($status, self::ENDED, true) => 'cancelled',
                $status === 'returned' => 'returned',
                in_array($status, self::IN_TRANSIT, true) => 'in_transit',
                default => $shipment->status === 'cancelled' ? 'shipped' : $shipment->status,
            },
            'meta' => [...(array) $shipment->meta, 'biteship' => $now],
        ])->save();
        if (isset($before['price'], $now['price']) && $before['price'] !== $now['price']) {
            $money = app(MoneyFormatter::class);
            $this->timeline->record($shipment->order, 'shipment', __('commerce-biteship::biteship.timeline.price', ['from' => $money->format((int) $before['price']), 'to' => $money->format((int) $now['price'])]),
                ['shipment_id' => $shipment->id], Actor::system());
        }
        if ($status !== '' && $status !== ($before['status'] ?? null)) {
            $this->timeline->record($shipment->order, 'shipment', __('commerce-biteship::biteship.timeline.status', ['status' => self::statusLabel($status)]),
                ['shipment_id' => $shipment->id, 'biteship_status' => $status], Actor::system());
        }
        if ($status === 'delivered') {
            $this->update->delivered($shipment, Actor::system());
        }

        return $shipment;
    }
}
