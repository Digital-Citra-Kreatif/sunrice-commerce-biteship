<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Biteship's REST API (https://biteship.com/en/docs): JSON in and out, the API key as the authorization header. */
class Client
{
    public function configured(): bool
    {
        return trim((string) config('commerce-biteship.api_key')) !== '';
    }

    /** A "biteship_test." key: orders are simulated. */
    public function testing(): bool
    {
        return str_starts_with((string) config('commerce-biteship.api_key'), 'biteship_test.');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function rates(array $body): array
    {
        return $this->send('post', '/v1/rates/couriers', $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createOrder(array $body): array
    {
        return $this->send('post', '/v1/orders', $body);
    }

    /** @return array<string, mixed> */
    public function order(string $id): array
    {
        return $this->send('get', '/v1/orders/'.rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function cancelOrder(string $id, string $reason): array
    {
        return $this->send('post', '/v1/orders/'.rawurlencode($id).'/cancel', ['cancellation_reason_code' => 'others', 'cancellation_reason' => $reason]);
    }

    /**
     * Areas matching a place name ("Sukajadi, Bandung"), best match first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function areas(string $input): array
    {
        return array_values((array) ($this->send('get', '/v1/maps/areas', ['countries' => 'ID', 'input' => $input, 'type' => 'single'])['areas'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws BiteshipException
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        if (! $this->configured()) {
            throw new BiteshipException('Biteship API key is not set (BITESHIP_API_KEY).');
        }
        try {
            $response = $this->http()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new BiteshipException('Biteship could not be reached: '.$e->getMessage());
        }
        $json = (array) $response->json();
        if ($response->failed() || ($json['success'] ?? true) === false) {
            throw new BiteshipException((string) ($json['error'] ?? $json['message'] ?? 'Biteship returned HTTP '.$response->status()), isset($json['code']) ? (int) $json['code'] : null, (array) ($json['details'] ?? []));
        }

        return $json;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('commerce-biteship.base_url'), '/'))
            ->withHeaders(['authorization' => (string) config('commerce-biteship.api_key')])
            ->acceptJson()->asJson()
            ->timeout((int) config('commerce-biteship.timeout', 15));
    }
}
