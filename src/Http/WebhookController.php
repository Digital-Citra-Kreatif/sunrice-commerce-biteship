<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sunrice\Commerce\Orders\Models\Shipment;
use Sunrice\CommerceBiteship\BiteshipException;
use Sunrice\CommerceBiteship\Bookings;
use Sunrice\CommerceBiteship\Client;

/**
 * Biteship's order.status, order.price and order.waybill_id webhooks.
 * Biteship doesn't sign them, so the body only says which booking
 * changed: the booking itself is fetched from the API before anything is
 * updated. Always 200, so Biteship's test ping and unknown bookings pass.
 */
class WebhookController
{
    public function __invoke(Request $request, Client $client, Bookings $bookings): JsonResponse
    {
        $token = (string) config('commerce-biteship.webhook_token');
        if ($token !== '' && ! hash_equals($token, (string) $request->query('token'))) {
            return response()->json(['ok' => false], 403);
        }
        $id = $request->input('order_id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return response()->json(['ok' => true]);
        }
        $shipment = Shipment::query()->with('order')->where('meta->biteship->order_id', $id)->first();
        if ($shipment === null) {
            return response()->json(['ok' => true]);
        }
        try {
            $bookings->apply($shipment, $client->order($id));
        } catch (BiteshipException $e) {
            report($e);

            return response()->json(['ok' => false], 503);   // Biteship retries
        }

        return response()->json(['ok' => true]);
    }
}
