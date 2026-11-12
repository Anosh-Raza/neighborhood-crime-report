<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Places\PlaceVocabularyService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildVocabularyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 50;

    public function __construct(public string $reportId)
    {
    }

    public function handle(PlaceVocabularyService $places): void
    {
        JobStateTracker::start($this->reportId, 'build_vocabulary');
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $vocab = $places->build(
                (float) $report->latitude(),
                (float) $report->longitude(),
                (float) config('crime_report.max_search_radius_km')
            );

            // Always seed listing locality so news search works even if Overpass is down.
            $seeds = array_values(array_filter([
                $report->city,
                $report->address,
                $report->country,
            ]));
            foreach (['neighborhoods', 'local_names'] as $bucket) {
                $vocab[$bucket] = array_values(array_unique(array_filter(array_merge(
                    $seeds,
                    $vocab[$bucket] ?? []
                ))));
            }

            $report->place_vocabulary = $vocab;
            $report->languages = array_values(array_unique(array_filter([
                'en',
                $this->guessLanguage($report->country_code),
            ])));
            $report->status = Report::STATUS_PROCESSING_RING_1;
            $report->save();

            JobStateTracker::done($this->reportId, 'build_vocabulary');
            FetchNewsJob::dispatch($this->reportId, '0-1');
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'build_vocabulary', $e->getMessage());
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }

    private function guessLanguage(?string $countryCode): ?string
    {
        return match (strtoupper((string) $countryCode)) {
            'FR' => 'fr',
            'IR' => 'fa',
            'MY' => 'ms',
            'PK' => 'ur',
            'IN' => 'hi',
            'ZA' => 'af',
            default => null,
        };
    }
}
