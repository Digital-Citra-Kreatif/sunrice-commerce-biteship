<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Sunrice\Commerce\Orders\Actions\CreateShipment;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\Models\OrderItem;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\Commerce\Orders\OrderStatus;
use Sunrice\Commerce\Orders\Timeline;
use Sunrice\Commerce\Shipping\Couriers;
use Sunrice\CommerceBiteship\BiteshipException;
use Sunrice\CommerceBiteship\BiteshipShipping;
use Sunrice\CommerceBiteship\Bookings;
use Throwable;

/**
 * Books the courier for an order shipped with Biteship: everything left
 * to ship goes in one parcel, which becomes a commerce shipment with the
 * waybill as its tracking number. A booking that was cancelled (or found
 * no courier) is booked again on the same shipment.
 */
class BookPickup
{
    public function __construct(
        protected BiteshipShipping $biteship,
        protected Bookings $bookings,
        protected CreateShipment $create,
        protected Couriers $couriers,
        protected Timeline $timeline,
    ) {}

    /** @throws ValidationException|BiteshipException */
    public function handle(Order $order, Actor $actor): Shipment
    {
        if (($order->shipping_method['method'] ?? null) !== BiteshipShipping::key()) {
            throw ValidationException::withMessages(['biteship' => 'This order is not shipped with Biteship.']);
        }
        if (! in_array($order->status, [OrderStatus::PROCESSING, OrderStatus::COMPLETED, OrderStatus::ON_HOLD], true)) {
            throw ValidationException::withMessages(['biteship' => 'Only processing orders can be booked.']);
        }

        return Cache::lock("commerce-biteship:book:{$order->id}", 30)->block(10, function () use ($order, $actor): Shipment {
            $order->refresh();
            $previous = $this->bookings->shipmentFor($order);
            if (Bookings::active($previous)) {
                throw ValidationException::withMessages(['biteship' => 'This order is already booked with Biteship.']);
            }
            $rebook = $previous !== null && $previous->status === 'cancelled';
            $attempt = (int) ($previous->meta['biteship']['attempt'] ?? 0) + 1;
            $shipment = $rebook ? $previous : new Shipment(['order_id' => $order->id, 'items' => $this->unshipped($order)]);
            if ($shipment->items === []) {
                throw ValidationException::withMessages(['biteship' => 'Everything in this order has been shipped.']);
            }
            $shipment->meta = [...(array) $shipment->meta, 'biteship' => [...(array) ($shipment->meta['biteship'] ?? []), 'attempt' => $attempt]];

            try {
                $booking = $this->biteship->book($order, $shipment);
            } catch (BiteshipException $e) {
                $this->timeline->record($order, 'shipment', __('commerce-biteship::biteship.timeline.booking_failed', ['message' => $e->getMessage()]), [], $actor);

                throw $e;
            }
            $meta = ['biteship' => [...$booking->meta, 'attempt' => $attempt, 'booked_at' => now()->toIso8601String()]];
            $company = (string) ($booking->meta['company'] ?? '');
            $courierName = BiteshipShipping::COURIERS[$company] ?? strtoupper($company);

            try {
                $shipment = $rebook ? $this->rebooked($shipment, $booking->trackingNumber, $meta) : $this->create->handle($order, [
                    'courier' => $this->preset($company),
                    'courier_name' => $courierName,
                    'service' => (string) ($order->shipping_method['label'] ?? $order->shipping_method['service'] ?? ''),
                    'tracking_number' => $booking->trackingNumber,
                    'cost' => $booking->meta['price'] ?? null,
                ], (array) $shipment->items, $actor);
            } catch (Throwable $e) {
                // The order changed while booking: don't leave a courier coming for nothing.
                rescue(fn () => app(CancelPickup::class)->cancelRemote((string) $booking->meta['order_id'], 'Order could not be shipped'));

                throw $e;
            }
            $shipment->forceFill([
                'tracking_url' => $booking->trackingUrl ?? $shipment->tracking_url,
                'meta' => [...(array) $shipment->meta, ...$meta],
            ])->save();
            $this->timeline->record($order, 'shipment', __('commerce-biteship::biteship.timeline.booked', ['courier' => $courierName]).($booking->trackingNumber !== '' ? " ({$booking->trackingNumber})" : ''),
                ['shipment_id' => $shipment->id], $actor);

            return $shipment;
        });
    }

    /** @return array<int, array{order_item_id: int, quantity: int}> */
    protected function unshipped(Order $order): array
    {
        return $order->items()->whereNull('parent_id')->where('requires_shipping', true)->get()
            ->filter(fn (OrderItem $i) => $i->unfulfilled() > 0)
            ->map(fn (OrderItem $i) => ['order_item_id' => $i->id, 'quantity' => $i->unfulfilled()])->values()->all();
    }

    /** @param  array<string, mixed>  $meta */
    protected function rebooked(Shipment $shipment, string $tracking, array $meta): Shipment
    {
        $shipment->forceFill(['status' => 'shipped', 'tracking_number' => $tracking !== '' ? $tracking : null, 'shipped_at' => now(), 'meta' => [...(array) $shipment->meta, ...$meta]])->save();

        return $shipment;
    }

    /** Commerce's courier preset for a Biteship company (GoSend is "gojek" at Biteship). */
    protected function preset(string $company): ?string
    {
        $key = $company === 'gojek' ? 'gosend' : $company;

        return $this->couriers->get($key) !== null ? $key : null;
    }
}
