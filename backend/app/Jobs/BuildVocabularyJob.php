<?php

namespace App\Jobs;

use App\Models\Report;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fast place seeds only — skips Overpass to keep reports under a few seconds.
 */
class BuildVocabularyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(public string $reportId)
    {
    }

    public function handle(): void
    {
        JobStateTracker::start($this->reportId, 'build_vocabulary');
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $seeds = array_values(array_filter([
                $report->area_keyword,
                $report->city,
                $report->address,
                $report->country,
            ]));

            $report->place_vocabulary = [
                'streets' => [],
                'neighborhoods' => $seeds,
                'landmarks' => [],
                'local_names' => $seeds,
            ];
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
