<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sunrice\Commerce\Orders\Actor;
use Sunrice\Commerce\Orders\Models\Order;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\CommerceBiteship\Actions\BookPickup;
use Sunrice\CommerceBiteship\Actions\CancelPickup;
use Sunrice\CommerceBiteship\BiteshipException;
use Sunrice\CommerceBiteship\Bookings;
use Sunrice\CommerceBiteship\Client;
use Sunrice\CommerceBiteship\Labels;
use Symfony\Component\HttpFoundation\Response;

/** The Biteship panel's buttons on the admin order page. */
class AdminController
{
    public function book(Request $request, Order $order, BookPickup $book): RedirectResponse
    {
        $this->guard();

        return $this->attempt(fn () => $book->handle($order, Actor::admin($request->user())), 'Pickup booked.');
    }

    public function cancel(Request $request, Order $order, Shipment $shipment, CancelPickup $cancel): RedirectResponse
    {
        $this->guard($order, $shipment);

        return $this->attempt(fn () => $cancel->handle($shipment, Actor::admin($request->user())), 'Booking cancelled.');
    }

    public function refresh(Order $order, Shipment $shipment, Client $client, Bookings $bookings): RedirectResponse
    {
        $this->guard($order, $shipment);

        return $this->attempt(fn () => $bookings->apply($shipment, $client->order((string) $shipment->meta['biteship']['order_id'])), 'Booking updated.');
    }

    public function label(Order $order, Shipment $shipment, Labels $labels): Response
    {
        $this->guard($order, $shipment);

        return response($labels->pdf($shipment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order->number).'-'.$shipment->id.'.pdf"',
        ]);
    }

    protected function guard(?Order $order = null, ?Shipment $shipment = null): void
    {
        abort_unless(commerce_feature('biteship'), 404);
        Gate::authorize('commerce.shipments.manage');
        abort_if($shipment !== null && ($shipment->order_id !== $order?->id || ! isset($shipment->meta['biteship']['order_id'])), 404);
    }

    protected function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BiteshipException $e) {
            throw ValidationException::withMessages(['biteship' => 'Biteship: '.$e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
