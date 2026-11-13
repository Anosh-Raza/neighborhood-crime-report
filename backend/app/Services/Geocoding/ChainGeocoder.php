<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tries Nominatim → Open-Meteo → Photon with progressive address simplification.
 */
class ChainGeocoder implements GeocoderInterface
{
    /** @param  list<GeocoderInterface>  $geocoders */
    public function __construct(private readonly array $geocoders)
    {
    }

    public function geocode(string $address, ?string $countryHint = null): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $cacheKey = 'geo:chain:'.md5($address.'|'.($countryHint ?? ''));

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($address, $countryHint) {
            foreach ($this->queryVariants($address, $countryHint) as $query) {
                foreach ($this->geocoders as $geocoder) {
                    try {
                        $result = $geocoder->geocode($query, $countryHint);
                    } catch (\Throwable $e) {
                        Log::warning('Geocoder threw', [
                            'provider' => $geocoder::class,
                            'query' => $query,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if ($result && isset($result['lat'], $result['lng'])) {
                        $result['query'] = $query;

                        return $result;
                    }
                }
            }

            Log::warning('All geocoders missed', ['address' => $address, 'country' => $countryHint]);

            return null;
        });
    }

    public function reverse(float $lat, float $lng): ?array
    {
        foreach ($this->geocoders as $geocoder) {
            try {
                $result = $geocoder->reverse($lat, $lng);
            } catch (\Throwable) {
                continue;
            }
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function queryVariants(string $address, ?string $countryHint): array
    {
        $variants = [$address];

        $parts = array_values(array_filter(array_map('trim', preg_split('/,/', $address) ?: [])));
        if (count($parts) >= 2) {
            // Drop middle administrative noise: "Area, City, State, Country" → "Area, Country"
            $variants[] = $parts[0].', '.$parts[count($parts) - 1];
            // First two chunks
            $variants[] = $parts[0].', '.$parts[1];
            // Area + country name from hint
            if ($countryHint) {
                $countryName = $this->countryName($countryHint);
                if ($countryName) {
                    $variants[] = $parts[0].', '.$countryName;
                }
            }
            // City-ish second token + country
            if (count($parts) >= 3) {
                $variants[] = $parts[1].', '.$parts[count($parts) - 1];
            }
            // Bare primary locality
            $variants[] = $parts[0];
        }

        // Split jammed tokens: "Pocharam Secunderabad" → try each + both
        $first = $parts[0] ?? $address;
        if (preg_match('/^([A-Za-z][A-Za-z\-]+)\s+([A-Za-z][A-Za-z\-\s]+)$/', $first, $m)) {
            $a = trim($m[1]);
            $b = trim($m[2]);
            $countryTail = count($parts) > 1 ? ', '.$parts[count($parts) - 1] : ($countryHint ? ', '.$this->countryName($countryHint) : '');
            $variants[] = $a.', '.$b.$countryTail;
            $variants[] = $a.$countryTail;
            $variants[] = $b.$countryTail;
        }

        $out = [];
        foreach ($variants as $v) {
            $v = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
            if ($v !== '' && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function countryName(string $code): ?string
    {
        return match (strtoupper($code)) {
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'PK' => 'Pakistan',
            'IN' => 'India',
            'AU' => 'Australia',
            'AE' => 'United Arab Emirates',
            'CA' => 'Canada',
            'JP' => 'Japan',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'PT' => 'Portugal',
            'NL' => 'Netherlands',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'NZ' => 'New Zealand',
            default => null,
        };
    }
}
