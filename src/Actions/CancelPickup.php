<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Actions;

use Illuminate\Validation\ValidationException;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\CommerceBiteship\Bookings;
use Sunrice\CommerceBiteship\Client;

/** Cancels the courier booking; the shipment is marked cancelled (on the timeline too) and can be booked again. */
class CancelPickup
{
    public function __construct(protected Client $client, protected Bookings $bookings) {}

    public function handle(Shipment $shipment, Actor $actor, string $reason = 'Cancelled by the store'): Shipment
    {
        if (! Bookings::active($shipment) || in_array($shipment->status, ['delivered', 'returned'], true)) {
            throw ValidationException::withMessages(['biteship' => 'This shipment has no open Biteship booking.']);
        }
        $this->cancelRemote((string) $shipment->meta['biteship']['order_id'], $reason);
        $this->bookings->apply($shipment, $this->client->order((string) $shipment->meta['biteship']['order_id']));

        return $shipment;
    }

    public function cancelRemote(string $biteshipOrderId, string $reason): void
    {
        $this->client->cancelOrder($biteshipOrderId, $reason);
    }
}
