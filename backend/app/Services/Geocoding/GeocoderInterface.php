<?php

namespace App\Services\Geocoding;

interface GeocoderInterface
{
    /**
     * @return array{lat: float, lng: float, display_name: string, country?: string, country_code?: string, city?: string}|null
     */
    public function geocode(string $address, ?string $countryHint = null): ?array;

    /**
     * @return array{display_name: string, country?: string, country_code?: string, city?: string}|null
     */
    public function reverse(float $lat, float $lng): ?array;
}
