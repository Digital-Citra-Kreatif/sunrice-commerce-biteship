<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Commerce\Health\HealthCheck;
use Sunrice\Commerce\Health\HealthResult;
use Sunrice\Commerce\Settings\StoreSettings;
use Sunrice\Commerce\Shipping\Models\ShippingMethod;
use Sunrice\Commerce\Support\Address;

/** The key is set (and live in production), the store address can be found, the admin panel is published. */
class BiteshipHealthCheck implements HealthCheck
{
    public function key(): string
    {
        return 'biteship';
    }

    public function label(): string
    {
        return 'Biteship shipping';
    }

    public function run(): HealthResult
    {
        if (! commerce_feature('biteship') || ! ShippingMethod::query()->where('type', BiteshipShipping::key())->where('active', true)->exists()) {
            return HealthResult::ok('No active Biteship shipping method.');
        }
        $client = app(Client::class);
        if (! $client->configured()) {
            return HealthResult::error('The Biteship API key is not set: checkout shows no Biteship rates.', 'Set BITESHIP_API_KEY in .env (Biteship dashboard → Integrations → API).');
        }
        $origin = app(Locations::class)->origin(Address::fromArray((array) app(StoreSettings::class)->get('general.address', [])));
        if ($origin['postal_code'] === null && $origin['area_id'] === null && ! Locations::hasCoordinates($origin)) {
            return HealthResult::error('Biteship can\'t tell where parcels leave from.', 'Add the postal code to the store address (Settings → Store → General) or set BITESHIP_ORIGIN_POSTAL_CODE.');
        }
        if ($client->testing() && app()->isProduction()) {
            return HealthResult::warning('Biteship is in Testing Mode: bookings are simulated and no courier comes.', 'Use a live key (biteship_live.…) in BITESHIP_API_KEY.');
        }
        if (! is_file(public_path('vendor/commerce-biteship/admin.js'))) {
            return HealthResult::warning('The Biteship panel for the order page isn\'t published.', 'Run php artisan vendor:publish --tag=commerce-biteship-assets --force');
        }

        return HealthResult::ok($client->testing() ? 'Testing Mode.' : 'Live.');
    }
}
