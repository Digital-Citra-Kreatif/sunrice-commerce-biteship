<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Illuminate\Support\Facades\Cache;
use Sunrice\Commerce\Regions\Region;
use Sunrice\Commerce\Support\Address;
use Throwable;

/**
 * Where an address is for Biteship: its postal code (typed, or the
 * village's from the region list), else Biteship's area for the district
 * name (cached), plus coordinates when they are known.
 */
class Locations
{
    public function __construct(protected Client $client) {}

    /** @return array{postal_code: int|null, area_id: string|null, latitude: float|null, longitude: float|null} */
    public function origin(Address $store): array
    {
        $point = $this->locate($store);
        $postal = config('commerce-biteship.origin.postal_code');
        $lat = config('commerce-biteship.origin.latitude');
        $lng = config('commerce-biteship.origin.longitude');

        return [
            'postal_code' => is_numeric($postal) ? (int) $postal : $point['postal_code'],
            'area_id' => is_numeric($postal) ? null : $point['area_id'],
            'latitude' => is_numeric($lat) ? (float) $lat : $point['latitude'],
            'longitude' => is_numeric($lng) ? (float) $lng : $point['longitude'],
        ];
    }

    /** @return array{postal_code: int|null, area_id: string|null, latitude: float|null, longitude: float|null} */
    public function locate(Address $address): array
    {
        $lat = $address->get('latitude');
        $lng = $address->get('longitude');
        $coordinates = is_numeric($lat) && is_numeric($lng) ? [(float) $lat, (float) $lng] : [null, null];
        $postal = $this->postalCode($address);

        return [
            'postal_code' => $postal,
            'area_id' => $postal === null ? $this->areaId($address) : null,
            'latitude' => $coordinates[0],
            'longitude' => $coordinates[1],
        ];
    }

    /**
     * Rate request fields: origin_postal_code, destination_area_id, origin_latitude…
     *
     * @param  array{postal_code: int|null, area_id: string|null, latitude: float|null, longitude: float|null}  $point
     * @return array<string, int|float|string>
     */
    public static function rateFields(string $prefix, array $point): array
    {
        return array_filter([
            "{$prefix}_postal_code" => $point['postal_code'],
            "{$prefix}_area_id" => $point['area_id'],
            "{$prefix}_latitude" => $point['latitude'],
            "{$prefix}_longitude" => $point['longitude'],
        ], fn ($v) => $v !== null);
    }

    /**
     * Order fields: origin_postal_code, origin_area_id, origin_coordinate.
     *
     * @param  array{postal_code: int|null, area_id: string|null, latitude: float|null, longitude: float|null}  $point
     * @return array<string, int|string|array{latitude: float, longitude: float}>
     */
    public static function orderFields(string $prefix, array $point): array
    {
        return array_filter([
            "{$prefix}_postal_code" => $point['postal_code'],
            "{$prefix}_area_id" => $point['area_id'],
            "{$prefix}_coordinate" => $point['latitude'] !== null && $point['longitude'] !== null
                ? ['latitude' => $point['latitude'], 'longitude' => $point['longitude']] : null,
        ], fn ($v) => $v !== null);
    }

    /** The address as one text, without the name and phone (Biteship and the label have contact fields for those). */
    public static function street(Address $address, string $separator = ', '): string
    {
        return Address::fromArray([...$address->toArray(), 'name' => null, 'phone' => null])->format(null, $separator);
    }

    /** @param  array{postal_code: int|null, area_id: string|null, latitude: float|null, longitude: float|null}  $point */
    public static function hasCoordinates(array $point): bool
    {
        return $point['latitude'] !== null && $point['longitude'] !== null;
    }

    protected function postalCode(Address $address): ?int
    {
        $postal = preg_replace('/\D/', '', (string) $address->get('postal_code'));
        if ($postal === '' && $address->get('village_code') !== null) {
            $postal = preg_replace('/\D/', '', (string) Region::query()->whereKey($address->get('village_code'))->value('postal_code'));
        }

        return $postal !== null && strlen($postal) === 5 ? (int) $postal : null;
    }

    /** Biteship's area for "district, city, province", looked up once a month. */
    protected function areaId(Address $address): ?string
    {
        $names = [];
        foreach (['district', 'city', 'province'] as $level) {
            $code = $address->get("{$level}_code");
            $names[] = $address->get($level) ?? ($code !== null ? Region::query()->whereKey($code)->value('name') : null);
        }
        $names = array_values(array_filter($names));
        if (count($names) < 2 || ! $this->client->configured()) {
            return null;
        }
        $input = implode(', ', $names);
        $key = 'commerce-biteship:area:'.md5(mb_strtolower($input));
        if (($cached = Cache::get($key)) !== null) {
            return $cached === '' ? null : (string) $cached;
        }
        try {
            $id = (string) ($this->client->areas($input)[0]['id'] ?? '');
        } catch (Throwable $e) {
            report($e);

            return null;   // not cached: try again next time
        }
        Cache::put($key, $id, now()->addDays(30));

        return $id === '' ? null : $id;
    }
}
