<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Listing\ListingParserService;
use App\Support\GeoPoint;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ParseListingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 50;

    public function __construct(public string $reportId)
    {
    }

    public function handle(ListingParserService $parser): void
    {
        JobStateTracker::start($this->reportId, 'parse_listing');
        $report = Report::query()->findOrFail($this->reportId);

        try {
            if (empty($report->location) || empty($report->address)) {
                $parsed = $parser->parse($report->listing_url);
                $report->address = $report->address ?: ($parsed['address'] ?? null);
                $report->city = $report->city ?: ($parsed['city'] ?? null);
                $report->country = $report->country ?: ($parsed['country'] ?? null);
                $report->country_code = $report->country_code ?: ($parsed['country_code'] ?? null);
                if (empty($report->location) && isset($parsed['lat'], $parsed['lng'])) {
                    $report->location = GeoPoint::make((float) $parsed['lng'], (float) $parsed['lat']);
                }
                $report->save();
            }

            JobStateTracker::done($this->reportId, 'parse_listing');
            GeocodeReportJob::dispatch($this->reportId);
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'parse_listing', $e->getMessage());
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            Log::error('ParseListingJob failed', ['report_id' => $this->reportId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
