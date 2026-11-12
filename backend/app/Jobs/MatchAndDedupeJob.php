<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Matching\IncidentMatcherService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class MatchAndDedupeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 55;

    public function __construct(
        public string $reportId,
        public string $ring = '0-1',
        public bool $isFinalBatch = false,
    ) {
    }

    public function handle(IncidentMatcherService $matcher): void
    {
        JobStateTracker::start($this->reportId, 'match_dedupe', $this->ring);
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $raw = Cache::get('report:'.$this->reportId.':raw_batch', []);
            $stored = $matcher->matchAndStore($report, $raw);
            Cache::forget('report:'.$this->reportId.':raw_batch');
            JobStateTracker::done($this->reportId, 'match_dedupe', $this->ring, ['stored' => $stored]);
            BuildReportJob::dispatch($this->reportId, $this->isFinalBatch);
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'match_dedupe', $e->getMessage(), $this->ring);
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }
}
