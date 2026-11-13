<?php

namespace App\Services\Report;

use App\Models\Incident;
use App\Models\LocationCache;
use App\Models\Report;
use App\Support\GridCell;
use App\Support\LegalFilters;
use Carbon\Carbon;

class ReportBuilderService
{
    public function rebuild(Report $report, bool $final = false): Report
    {
        $incidents = Incident::query()
            ->where('report_id', (string) $report->_id)
            ->get();

        $ringCounts = [];
        foreach (config('crime_report.rings') as $ring) {
            $ringCounts[$ring['key']] = 0;
        }
        $ringCounts['unclear'] = 0;

        $crimeTypes = [];
        $years = [];

        foreach ($incidents as $incident) {
            $ring = $incident->ring ?: 'unclear';
            $ringCounts[$ring] = ($ringCounts[$ring] ?? 0) + 1;
            $type = $incident->crime_type ?: 'other';
            $crimeTypes[$type] = ($crimeTypes[$type] ?? 0) + 1;
            if ($incident->incident_date) {
                $y = $incident->incident_date->format('Y');
                $years[$y] = ($years[$y] ?? 0) + 1;
            }
        }

        ksort($years);

        $report->ring_counts = $ringCounts;
        $report->crime_type_counts = $crimeTypes;
        $report->yearly_trend = collect($years)->map(fn ($count, $year) => [
            'year' => (string) $year,
            'count' => $count,
        ])->values()->all();
        $mentionCount = (int) data_get($report->news_mentions, 'totals.article_count', 0);
        $report->coverage_rating = $this->coverageRating(max($incidents->count(), $mentionCount), $report->languages ?? []);
        $report->summary = $this->summary($report, $incidents->count(), $ringCounts);
        $report->disclaimer = LegalFilters::disclaimer();

        if ($final) {
            $report->status = Report::STATUS_COMPLETE;
            $this->warmLocationCache($report, $incidents);
        }

        $report->save();

        return $report->fresh();
    }

    private function warmLocationCache(Report $report, $incidents): void
    {
        if ($report->latitude() === null || $report->longitude() === null) {
            return;
        }

        LocationCache::query()->updateOrCreate(
            ['grid_key' => GridCell::key((float) $report->latitude(), (float) $report->longitude())],
            [
                'country' => $report->country_code,
                'incidents' => $incidents->take(100)->map(fn (Incident $i) => [
                    'crime_type' => $i->crime_type,
                    'distance_km' => $i->distance_km,
                    'summary' => $i->summary,
                    'sources' => $i->sources,
                ])->values()->all(),
                'updated_at' => Carbon::now(),
            ]
        );
    }

    private function coverageRating(int $incidentCount, array $languages): string
    {
        $langCount = max(1, count(array_filter($languages)));
        if ($incidentCount >= 12 && $langCount >= 2) {
            return 'High';
        }
        if ($incidentCount >= 4) {
            return 'Medium';
        }

        return 'Low';
    }

    private function summary(Report $report, int $count, array $ringCounts): string
    {
        $near = (int) ($ringCounts['0-1'] ?? 0);
        $keyword = $report->area_keyword ?: ($report->address ?: 'the selected location');
        $mentionArticles = (int) data_get($report->news_mentions, 'totals.article_count', 0);
        $titleHits = (int) data_get($report->news_mentions, 'totals.title_hit_count', 0);

        return sprintf(
            'News search for “%s” found %d articles (%d with the area in the headline). Separately, %d crime-tagged items were mapped near the pin (%d within 1 km). Coverage rating: %s. %s',
            $keyword,
            $mentionArticles,
            $titleHits,
            $count,
            $near,
            $report->coverage_rating ?? 'Low',
            LegalFilters::disclaimer()
        );
    }
}
