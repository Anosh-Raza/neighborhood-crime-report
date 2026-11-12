<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NominatimGeocoder implements GeocoderInterface
{
    public function geocode(string $address, ?string $countryHint = null): ?array
    {
        $cacheKey = 'geo:fwd:'.md5($address.'|'.($countryHint ?? ''));

        return Cache::remember($cacheKey, now()->addDays(14), function () use ($address, $countryHint) {
            $query = [
                'q' => $address,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => 1,
            ];

            if ($countryHint) {
                $query['countrycodes'] = strtolower($countryHint);
            }

            $request = Http::withHeaders([
                'User-Agent' => config('services.nominatim.user_agent'),
            ])->timeout(20);

            if (app()->environment('local')) {
                $request = $request->withOptions(['verify' => false]);
            }

            $response = $request->get(config('services.nominatim.base_url').'/search', $query);

            if (! $response->successful() || empty($response->json()[0] ?? null)) {
                Log::warning('Nominatim geocode miss', ['address' => $address, 'status' => $response->status()]);

                return null;
            }

            $row = $response->json()[0];

            return $this->mapResult($row);
        });
    }

    public function reverse(float $lat, float $lng): ?array
    {
        $cacheKey = 'geo:rev:'.md5(sprintf('%.5f,%.5f', $lat, $lng));

        return Cache::remember($cacheKey, now()->addDays(14), function () use ($lat, $lng) {
            $request = Http::withHeaders([
                'User-Agent' => config('services.nominatim.user_agent'),
            ])->timeout(20);

            if (app()->environment('local')) {
                $request = $request->withOptions(['verify' => false]);
            }

            $response = $request->get(config('services.nominatim.base_url').'/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'jsonv2',
                'addressdetails' => 1,
            ]);

            if (! $response->successful()) {
                return null;
            }

            return $this->mapResult($response->json());
        });
    }

    private function mapResult(array $row): ?array
    {
        if (! isset($row['lat'], $row['lon'])) {
            return null;
        }

        $address = $row['address'] ?? [];

        return [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lon'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'country' => $address['country'] ?? null,
            'country_code' => isset($address['country_code']) ? strtoupper($address['country_code']) : null,
            'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['suburb'] ?? null,
        ];
    }
}
