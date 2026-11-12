<?php

namespace App\Services\Matching;

use App\Models\Incident;
use App\Models\Report;
use App\Services\Geocoding\GeocodingService;
use App\Support\DedupeHash;
use App\Support\GeoPoint;
use Carbon\Carbon;

class IncidentMatcherService
{
    public function __construct(private readonly GeocodingService $geocoding)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawIncidents
     */
    public function matchAndStore(Report $report, array $rawIncidents): int
    {
        $lat = $report->latitude();
        $lng = $report->longitude();
        if ($lat === null || $lng === null) {
            return 0;
        }

        $stored = 0;
        foreach ($rawIncidents as $raw) {
            $locationText = trim((string) ($raw['location_text'] ?? ''));
            $geo = null;
            if ($locationText !== '') {
                $query = $locationText;
                if ($report->city) {
                    $query .= ', '.$report->city;
                }
                if ($report->country) {
                    $query .= ', '.$report->country;
                }
                $geo = $this->geocoding->geocode($query, $report->country_code);
            }

            $precision = $raw['precision'] ?? 'neighborhood-only';
            $unclear = $precision === 'neighborhood-only' || $geo === null;
            $iLat = $geo['lat'] ?? null;
            $iLng = $geo['lng'] ?? null;
            $distance = ($iLat !== null && $iLng !== null)
                ? GeoPoint::distanceKm($lat, $lng, $iLat, $iLng)
                : null;

            // City-only / coarse pins often geocode far from the property. Keep them as
            // "unclear exact location" instead of discarding the news item.
            if ($distance !== null && $distance > config('crime_report.max_search_radius_km')) {
                if ($unclear) {
                    $distance = null;
                    $iLat = null;
                    $iLng = null;
                } else {
                    continue;
                }
            }

            $ring = $distance !== null ? GeoPoint::ringForDistance($distance) : null;
            if ($distance !== null && $ring === null) {
                if ($unclear) {
                    $distance = null;
                    $iLat = null;
                    $iLng = null;
                    $ring = null;
                } else {
                    continue;
                }
            }

            $date = $raw['incident_date'] ?? null;
            $hash = DedupeHash::make(
                (string) $raw['crime_type'],
                is_string($date) ? $date : null,
                $locationText !== '' ? $locationText : ($report->city ?? 'unknown'),
                $iLat,
                $iLng,
            );

            $existing = Incident::query()
                ->where('report_id', (string) $report->_id)
                ->where('dedupe_hash', $hash)
                ->first();

            $source = $raw['source'] ?? null;
            if (! is_array($source) || empty($source['url'])) {
                continue;
            }

            if ($existing) {
                $sources = $existing->sources ?? [];
                $urls = array_column($sources, 'url');
                if (! in_array($source['url'], $urls, true)) {
                    $sources[] = $source;
                    $existing->sources = $sources;
                    $existing->confidence = min(0.99, ((float) $existing->confidence) + 0.08);
                    $existing->save();
                }
                continue;
            }

            Incident::query()->create([
                'report_id' => (string) $report->_id,
                'crime_type' => (string) $raw['crime_type'],
                'incident_date' => $this->parseDate(is_string($date) ? $date : null),
                'location' => ($iLat !== null && $iLng !== null) ? GeoPoint::make($iLng, $iLat) : null,
                'location_text' => $locationText,
                'precision' => $precision,
                'distance_km' => $distance,
                'ring' => $ring ?? 'unclear',
                'status' => $raw['status'] ?? 'allegation',
                'confidence' => $this->confidence($precision, $distance !== null, false),
                'summary' => (string) $raw['summary'],
                'sources' => [$source],
                'dedupe_hash' => $hash,
                'language' => $raw['language'] ?? 'en',
                'unclear_exact_location' => $unclear,
            ]);
            $stored++;
        }

        return $stored;
    }

    private function confidence(string $precision, bool $hasGeo, bool $multiSource): float
    {
        $score = match ($precision) {
            'exact' => 0.85,
            'street-or-landmark' => 0.7,
            default => 0.45,
        };
        if ($hasGeo) {
            $score += 0.1;
        }
        if ($multiSource) {
            $score += 0.08;
        }

        return min(0.99, round($score, 2));
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
