<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free place-name geocoder (GeoNames-backed). No API key required.
 * @see https://open-meteo.com/en/docs/geocoding-api
 */
class OpenMeteoGeocoder implements GeocoderInterface
{
    public function geocode(string $address, ?string $countryHint = null): ?array
    {
        $name = $this->toPlaceName($address);
        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        $query = [
            'name' => $name,
            'count' => 5,
            'language' => 'en',
            'format' => 'json',
        ];
        if ($countryHint) {
            $query['countryCode'] = strtoupper($countryHint);
        }

        $request = Http::timeout(6)->connectTimeout(3);
        if (app()->environment('local')) {
            $request = $request->withOptions(['verify' => false]);
        }

        try {
            $response = $request->get('https://geocoding-api.open-meteo.com/v1/search', $query);
        } catch (\Throwable $e) {
            Log::warning('Open-Meteo geocode error', ['address' => $address, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Open-Meteo geocode HTTP fail', ['address' => $address, 'status' => $response->status()]);

            return null;
        }

        $results = $response->json('results') ?? [];
        if (! is_array($results) || $results === []) {
            return null;
        }

        $row = $results[0];
        if (! isset($row['latitude'], $row['longitude'])) {
            return null;
        }

        return [
            'lat' => (float) $row['latitude'],
            'lng' => (float) $row['longitude'],
            'display_name' => implode(', ', array_filter([
                $row['name'] ?? null,
                $row['admin1'] ?? null,
                $row['country'] ?? null,
            ])),
            'country' => $row['country'] ?? null,
            'country_code' => isset($row['country_code']) ? strtoupper((string) $row['country_code']) : null,
            'city' => $row['name'] ?? null,
            'provider' => 'open_meteo',
        ];
    }

    public function reverse(float $lat, float $lng): ?array
    {
        return null;
    }

    private function toPlaceName(string $address): string
    {
        // Open-Meteo prefers "Place, Country" rather than full street lines.
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        if ($parts === []) {
            return trim($address);
        }

        // Drop pure house-number prefixes when present as first token only.
        $first = preg_replace('/^\d+[A-Za-z]?\s+/', '', $parts[0]) ?? $parts[0];
        $parts[0] = trim($first);

        if (count($parts) >= 2) {
            return $parts[0].', '.$parts[count($parts) - 1];
        }

        return $parts[0];
    }
}
