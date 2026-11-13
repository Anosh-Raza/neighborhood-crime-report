<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseListingJob;
use App\Models\CreditLedger;
use App\Models\Incident;
use App\Models\JobState;
use App\Models\Report;
use App\Services\Geocoding\GeocodingService;
use App\Services\Listing\ListingParserService;
use App\Services\Places\AreaKeywordService;
use App\Support\GeoPoint;
use App\Support\LegalFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ReportController extends Controller
{
    public function preview(Request $request, ListingParserService $parser, GeocodingService $geocoding, AreaKeywordService $areas): JsonResponse
    {
        $data = $request->validate([
            'listing_url' => ['nullable', 'url', 'max:2000'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        if (empty($data['listing_url']) && empty($data['address'])) {
            return response()->json(['message' => 'Provide a listing_url or address.'], 422);
        }

        $key = 'preview:'.($request->user()?->_id ?? $request->ip());
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json(['message' => 'Too many preview requests.'], 429);
        }
        RateLimiter::hit($key, 60);

        $parsed = [
            'listing_url' => $data['listing_url'] ?? null,
            'address' => $data['address'] ?? null,
            'source' => 'address',
        ];

        if (! empty($data['listing_url'])) {
            $parsed = array_merge($parsed, $parser->parse($data['listing_url']));
            $parsed['listing_url'] = $data['listing_url'];
            if (! empty($data['address'])) {
                $parsed['address'] = $data['address'];
            }
        }

        $listingUrl = $parsed['listing_url']
            ?? (! empty($parsed['address'])
                ? 'https://www.openstreetmap.org/search?query='.rawurlencode((string) $parsed['address'])
                : null);

        $area = $areas->resolve(
            $listingUrl,
            $parsed['address'] ?? $data['address'] ?? null,
            $parsed['city'] ?? null,
            $parsed['country_code'] ?? null,
        );

        // Prefer cleaned locality for display/geocode when area resolver found a real place.
        if (! empty($area['geocode_query']) && strcasecmp((string) ($area['primary'] ?? ''), 'Unknown area') !== 0) {
            $parsed['address'] = $area['geocode_query'];
        } elseif (! empty($area['primary']) && strcasecmp($area['primary'], 'Unknown area') !== 0) {
            $parsed['address'] = implode(', ', array_filter([
                $area['primary'],
                $area['city'] ?? $parsed['city'] ?? null,
                $parsed['country'] ?? null,
            ]));
        }
        if (! empty($area['city'])) {
            $parsed['city'] = $area['city'];
        }
        if (! empty($area['country_code'])) {
            $parsed['country_code'] = $area['country_code'];
        }

        if (empty($parsed['lat']) || empty($parsed['lng'])) {
            $countryCode = $parsed['country_code'] ?? $area['country_code'] ?? null;
            $primary = ! empty($area['primary']) && strcasecmp((string) $area['primary'], 'Unknown area') !== 0
                ? (string) $area['primary']
                : null;
            $candidates = array_values(array_unique(array_filter([
                $parsed['address'] ?? null,
                $area['geocode_query'] ?? null,
                $primary
                    ? implode(', ', array_filter([$primary, $parsed['city'] ?? null, $parsed['country'] ?? null]))
                    : null,
                $primary && $countryCode === 'IN'
                    ? $primary.', India'
                    : null,
                $primary,
                ! empty($parsed['city']) && ! empty($parsed['country'])
                    ? $parsed['city'].', '.$parsed['country']
                    : null,
                $data['address'] ?? null,
            ])));

            foreach ($candidates as $addressForGeo) {
                $geo = $geocoding->geocode($addressForGeo, $countryCode);
                if (! $geo) {
                    continue;
                }
                $parsed['lat'] = $geo['lat'];
                $parsed['lng'] = $geo['lng'];
                $parsed['country'] = $parsed['country'] ?? $geo['country'];
                $parsed['country_code'] = $parsed['country_code'] ?? $geo['country_code'];
                $parsed['city'] = $parsed['city'] ?? $geo['city'];
                $parsed['source'] = ($parsed['source'] ?? 'address').'+geocode';
                if (! empty($geo['provider'])) {
                    $parsed['geocoder'] = $geo['provider'];
                }
                break;
            }
        }

        return response()->json([
            'preview' => [
                'listing_url' => $listingUrl,
                'title' => $parsed['title'] ?? null,
                'address' => $parsed['address'] ?? null,
                'city' => $parsed['city'] ?? null,
                'country' => $parsed['country'] ?? null,
                'country_code' => $parsed['country_code'] ?? null,
                'lat' => isset($parsed['lat']) ? (float) $parsed['lat'] : null,
                'lng' => isset($parsed['lng']) ? (float) $parsed['lng'] : null,
                'source' => $parsed['source'] ?? null,
                'area_keyword' => $area['primary'],
                'area_aliases' => $area['aliases'],
                'search_terms' => $area['search_terms'],
            ],
            'area' => $area,
            'allowed_radii_km' => config('crime_report.allowed_radii_km'),
            'user_max_radius_km' => 10,
            'disclaimer' => LegalFilters::disclaimer(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_url' => ['required', 'url', 'max:2000'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'requested_radius_km' => ['required', 'integer', 'in:1,3,5,10'],
        ]);

        $user = $request->user();
        $radius = (int) $data['requested_radius_km'];

        // Auth/credits/plans are deferred — open MVP allows all radii for guests.
        if ($user && ! $user->canUseRadius($radius) && config('crime_report.enforce_billing', false)) {
            return response()->json([
                'message' => 'Your plan only unlocks up to '.$user->max_radius_km.' km. Upgrade to unlock 3/5/10 km filters.',
            ], 402);
        }

        if ($user && ! $user->hasCredits() && config('crime_report.enforce_billing', false)) {
            return response()->json(['message' => 'No report credits remaining.'], 402);
        }

        $area = app(AreaKeywordService::class)->resolve(
            $data['listing_url'],
            $data['address'],
            $data['city'] ?? null,
            isset($data['country_code']) ? strtoupper($data['country_code']) : null,
        );

        $report = Report::query()->create([
            'user_id' => $user ? (string) $user->_id : null,
            'listing_url' => $data['listing_url'],
            'address' => $data['address'],
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
            'location' => GeoPoint::make((float) $data['lng'], (float) $data['lat']),
            'requested_radius_km' => $radius,
            'status' => Report::STATUS_QUEUED,
            'disclaimer' => LegalFilters::disclaimer(),
            'area_keyword' => $area['primary'],
            'area_aliases' => $area['aliases'],
            'area_meta' => $area,
        ]);

        if ($user && config('crime_report.enforce_billing', false)) {
            $user->consumeCredit();
            CreditLedger::query()->create([
                'user_id' => (string) $user->_id,
                'delta' => -1,
                'reason' => 'report_created',
                'report_id' => (string) $report->_id,
            ]);
        }

        ParseListingJob::dispatch((string) $report->_id);

        return response()->json([
            'report' => $this->reportSummary($report),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = Report::query()
            ->where('user_id', (string) $request->user()->_id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Report $r) => $this->reportSummary($r));

        return response()->json(['reports' => $reports]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $report = $this->findReport($request, $id);
        $radius = (int) $request->query('radius_km', $report->requested_radius_km ?: 1);
        if (! in_array($radius, config('crime_report.allowed_radii_km'), true)) {
            $radius = 1;
        }
        if ($request->user() && config('crime_report.enforce_billing', false) && ! $request->user()->canUseRadius($radius)) {
            $radius = min($radius, (int) $request->user()->max_radius_km);
        }

        $query = Incident::query()->where('report_id', (string) $report->_id);
        $query->where(function ($q) use ($radius) {
            $q->where('distance_km', '<=', $radius)
                ->orWhere(function ($q2) {
                    $q2->where('unclear_exact_location', true)
                        ->where(function ($q3) {
                            $q3->whereNull('distance_km')->orWhere('distance_km', '<=', 10);
                        });
                });
        });

        if ($crimeType = $request->query('crime_type')) {
            $query->where('crime_type', $crimeType);
        }
        if ($from = $request->query('date_from')) {
            $query->where('incident_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('incident_date', '<=', $to);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));
        $total = (clone $query)->count();
        $incidents = $query->orderBy('distance_km', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $clear = [];
        $unclear = [];
        foreach ($incidents as $incident) {
            $payload = [
                'id' => (string) $incident->_id,
                'crime_type' => $incident->crime_type,
                'incident_date' => optional($incident->incident_date)?->toDateString(),
                'location_text' => $incident->location_text,
                'precision' => $incident->precision,
                'distance_km' => $incident->distance_km,
                'ring' => $incident->ring,
                'status' => $incident->status,
                'confidence' => $incident->confidence,
                'summary' => $incident->summary,
                'sources' => $incident->sources,
                'lat' => $incident->location['coordinates'][1] ?? null,
                'lng' => $incident->location['coordinates'][0] ?? null,
                'unclear_exact_location' => (bool) $incident->unclear_exact_location,
            ];
            if ($incident->unclear_exact_location) {
                $unclear[] = $payload;
            } else {
                $clear[] = $payload;
            }
        }

        // Also return all map pins within 10 km for client-side radius filtering.
        $mapIncidents = Incident::query()
            ->where('report_id', (string) $report->_id)
            ->where(function ($q) {
                $q->where('distance_km', '<=', 10)->orWhere('unclear_exact_location', true);
            })
            ->get(['_id', 'crime_type', 'distance_km', 'ring', 'location', 'unclear_exact_location', 'summary', 'status', 'confidence']);

        return response()->json([
            'report' => $this->reportDetail($report),
            'filter' => [
                'radius_km' => $radius,
                'crime_type' => $crimeType,
                'date_from' => $from,
                'date_to' => $to,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'incidents' => $clear,
            'unclear_incidents' => $unclear,
            'map_incidents' => $mapIncidents->map(fn (Incident $i) => [
                'id' => (string) $i->_id,
                'crime_type' => $i->crime_type,
                'distance_km' => $i->distance_km,
                'ring' => $i->ring,
                'lat' => $i->location['coordinates'][1] ?? null,
                'lng' => $i->location['coordinates'][0] ?? null,
                'unclear_exact_location' => (bool) $i->unclear_exact_location,
                'summary' => $i->summary,
                'status' => $i->status,
                'confidence' => $i->confidence,
            ]),
            'disclaimer' => LegalFilters::disclaimer(),
        ]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $report = $this->findReport($request, $id);
        $jobs = JobState::query()
            ->where('report_id', (string) $report->_id)
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(fn (JobState $j) => [
                'step' => $j->step,
                'ring' => $j->ring,
                'status' => $j->status,
                'attempts' => $j->attempts,
                'error' => $j->error,
                'updated_at' => optional($j->updated_at)?->toIso8601String(),
            ]);

        return response()->json([
            'id' => (string) $report->_id,
            'status' => $report->status,
            'coverage_rating' => $report->coverage_rating,
            'error_message' => $report->error_message,
            'jobs' => $jobs,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $report = $this->ownedReport($request, $id);
        Incident::query()->where('report_id', (string) $report->_id)->delete();
        JobState::query()->where('report_id', (string) $report->_id)->delete();
        $report->delete();

        return response()->json(['message' => 'Report deleted']);
    }

    private function findReport(Request $request, string $id): Report
    {
        $query = Report::query()->where('_id', $id);

        // When billing is enforced, authenticated users only see their own reports.
        if ($request->user() && config('crime_report.enforce_billing', false)) {
            $query->where('user_id', (string) $request->user()->_id);
        }

        return $query->firstOrFail();
    }

    private function ownedReport(Request $request, string $id): Report
    {
        return Report::query()
            ->where('_id', $id)
            ->where('user_id', (string) $request->user()->_id)
            ->firstOrFail();
    }

    private function reportSummary(Report $report): array
    {
        return [
            'id' => (string) $report->_id,
            'listing_url' => $report->listing_url,
            'address' => $report->address,
            'city' => $report->city,
            'country' => $report->country,
            'status' => $report->status,
            'coverage_rating' => $report->coverage_rating,
            'requested_radius_km' => $report->requested_radius_km,
            'lat' => $report->latitude(),
            'lng' => $report->longitude(),
            'created_at' => optional($report->created_at)?->toIso8601String(),
        ];
    }

    private function reportDetail(Report $report): array
    {
        return array_merge($this->reportSummary($report), [
            'summary' => $report->summary,
            'disclaimer' => $report->disclaimer ?: LegalFilters::disclaimer(),
            'ring_counts' => $report->ring_counts,
            'crime_type_counts' => $report->crime_type_counts,
            'yearly_trend' => $report->yearly_trend,
            'country_code' => $report->country_code,
            'area_keyword' => $report->area_keyword,
            'area_aliases' => $report->area_aliases ?? [],
            'area_meta' => $report->area_meta,
            'news_mentions' => $report->news_mentions,
        ]);
    }
}
