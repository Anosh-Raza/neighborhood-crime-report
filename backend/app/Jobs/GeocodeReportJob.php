<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Geocoding\GeocodingService;
use App\Support\GeoPoint;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 40;

    public function __construct(public string $reportId)
    {
    }

    public function handle(GeocodingService $geocoding): void
    {
        JobStateTracker::start($this->reportId, 'geocode');
        $report = Report::query()->findOrFail($this->reportId);

        try {
            if (empty($report->location) && $report->address) {
                $geo = $geocoding->geocode($report->address, $report->country_code);
                if (! $geo) {
                    throw new \RuntimeException('Unable to geocode listing address.');
                }
                $report->location = GeoPoint::make($geo['lng'], $geo['lat']);
                $report->country = $report->country ?: ($geo['country'] ?? null);
                $report->country_code = $report->country_code ?: ($geo['country_code'] ?? null);
                $report->city = $report->city ?: ($geo['city'] ?? null);
                $report->address = $report->address ?: ($geo['display_name'] ?? null);
                $report->save();
            } elseif ($report->latitude() !== null && $report->longitude() !== null && ! $report->address) {
                $rev = $geocoding->reverse($report->latitude(), $report->longitude());
                if ($rev) {
                    $report->address = $rev['display_name'] ?? $report->address;
                    $report->country = $report->country ?: ($rev['country'] ?? null);
                    $report->country_code = $report->country_code ?: ($rev['country_code'] ?? null);
                    $report->city = $report->city ?: ($rev['city'] ?? null);
                    $report->save();
                }
            }

            if ($report->latitude() === null || $report->longitude() === null) {
                throw new \RuntimeException('Report is missing coordinates.');
            }

            JobStateTracker::done($this->reportId, 'geocode');
            BuildVocabularyJob::dispatch($this->reportId);
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'geocode', $e->getMessage());
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }
}
