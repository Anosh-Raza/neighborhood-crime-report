<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\News\NewsSearchService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FetchNewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 50;

    public function __construct(public string $reportId, public string $ring = '0-1')
    {
    }

    public function handle(NewsSearchService $news): void
    {
        JobStateTracker::start($this->reportId, 'fetch_news', $this->ring);
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $limit = (int) config('crime_report.news_max_articles_per_report');
            $articles = $news->searchForReport(
                $report->place_vocabulary ?? [],
                $report->country_code,
                $report->city,
                $limit
            );

            Cache::put('report:'.$this->reportId.':articles', $articles, now()->addHours(6));
            Cache::put('report:'.$this->reportId.':extract_offset', 0, now()->addHours(6));
            JobStateTracker::done($this->reportId, 'fetch_news', $this->ring, ['count' => count($articles)]);
            ExtractIncidentsJob::dispatch($this->reportId, '0-1');
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'fetch_news', $e->getMessage(), $this->ring);
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }
}
