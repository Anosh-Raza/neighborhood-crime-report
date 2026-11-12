<?php

namespace App\Services\Geocoding;

class GeocodingService
{
    public function __construct(private readonly GeocoderInterface $geocoder)
    {
    }

    public function geocode(string $address, ?string $countryHint = null): ?array
    {
        return $this->geocoder->geocode($address, $countryHint);
    }

    public function reverse(float $lat, float $lng): ?array
    {
        return $this->geocoder->reverse($lat, $lng);
    }
}
