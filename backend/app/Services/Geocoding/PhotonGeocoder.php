<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photon (Komoot) — free OSM geocoder, often more forgiving than public Nominatim.
 * @see https://photon.komoot.io
 */
class PhotonGeocoder implements GeocoderInterface
{
    public function geocode(string $address, ?string $countryHint = null): ?array
    {
        $query = [
            'q' => $address,
            'limit' => 5,
            'lang' => 'en',
        ];

        $request = Http::timeout(6)->connectTimeout(3)->withHeaders([
            'User-Agent' => config('services.nominatim.user_agent', 'Houzing/1.0'),
        ]);
        if (app()->environment('local')) {
            $request = $request->withOptions(['verify' => false]);
        }

        try {
            $response = $request->get('https://photon.komoot.io/api/', $query);
        } catch (\Throwable $e) {
            Log::warning('Photon geocode error', ['address' => $address, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $features = $response->json('features') ?? [];
        if (! is_array($features) || $features === []) {
            return null;
        }

        foreach ($features as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? null;
            $props = $feature['properties'] ?? [];
            if (! is_array($coords) || count($coords) < 2) {
                continue;
            }

            $featureCc = isset($props['countrycode']) ? strtoupper((string) $props['countrycode']) : null;
            if ($countryHint && $featureCc && strtoupper($countryHint) !== $featureCc) {
                continue;
            }

            $lng = (float) $coords[0];
            $lat = (float) $coords[1];

            return [
                'lat' => $lat,
                'lng' => $lng,
                'display_name' => implode(', ', array_filter([
                    $props['name'] ?? null,
                    $props['city'] ?? $props['town'] ?? $props['village'] ?? null,
                    $props['state'] ?? null,
                    $props['country'] ?? null,
                ])),
                'country' => $props['country'] ?? null,
                'country_code' => $featureCc,
                'city' => $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['name'] ?? null,
                'provider' => 'photon',
            ];
        }

        return null;
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $request = Http::timeout(6)->connectTimeout(3)->withHeaders([
            'User-Agent' => config('services.nominatim.user_agent', 'Houzing/1.0'),
        ]);
        if (app()->environment('local')) {
            $request = $request->withOptions(['verify' => false]);
        }

        try {
            $response = $request->get('https://photon.komoot.io/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'lang' => 'en',
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $feature = $response->json('features.0');
        if (! is_array($feature)) {
            return null;
        }

        $coords = $feature['geometry']['coordinates'] ?? null;
        $props = $feature['properties'] ?? [];
        if (! is_array($coords) || count($coords) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coords[1],
            'lng' => (float) $coords[0],
            'display_name' => (string) ($props['name'] ?? ''),
            'country' => $props['country'] ?? null,
            'country_code' => isset($props['countrycode']) ? strtoupper((string) $props['countrycode']) : null,
            'city' => $props['city'] ?? $props['town'] ?? $props['village'] ?? null,
            'provider' => 'photon',
        ];
    }
}
