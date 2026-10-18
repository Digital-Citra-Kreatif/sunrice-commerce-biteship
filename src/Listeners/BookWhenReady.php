<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Events\OrderPaid;
use Sunrice\Commerce\Orders\Events\OrderPlaced;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\CommerceBiteship\Actions\BookPickup;
use Sunrice\CommerceBiteship\BiteshipException;
use Sunrice\CommerceBiteship\BiteshipShipping;

/**
 * Books the pickup by itself when the order's Biteship method says so
 * ("When the order is paid"): on OrderPaid, and on OrderPlaced for cash
 * on delivery. A failed booking is on the order's timeline; book it from
 * the order page.
 */
class BookWhenReady implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function viaQueue(): ?string
    {
        return config('commerce-biteship.queue');
    }

    public function handle(OrderPaid|OrderPlaced $event): void
    {
        $order = $event->order->fresh();
        if ($order === null || ! self::wanted($order, $event instanceof OrderPlaced)) {
            return;
        }
        try {
            app(BookPickup::class)->handle($order, Actor::system());
        } catch (ValidationException) {
            // Already booked, shipped by hand, or no longer processing: nothing to do.
        } catch (BiteshipException $e) {
            // Biteship's own refusals (address, balance) don't get better by retrying.
            if ($e->errorCode === null) {
                throw $e;
            }
        }
    }

    public static function wanted(Order $order, bool $placed): bool
    {
        if (! commerce_feature('biteship') || ($order->shipping_method['method'] ?? null) !== BiteshipShipping::key()) {
            return false;
        }
        if ($placed && $order->payment_method !== 'cod') {
            return false;
        }
        $method = ShippingMethod::query()->find($order->shipping_method['method_id'] ?? null);

        return $method?->setting('booking') === 'paid';
    }
}
