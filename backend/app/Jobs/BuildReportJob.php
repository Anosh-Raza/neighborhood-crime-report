<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Report\ReportBuilderService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class BuildReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 40;

    public function __construct(public string $reportId, public bool $final = false)
    {
    }

    public function handle(ReportBuilderService $builder): void
    {
        $stepRing = $this->final ? 'final' : 'partial';
        JobStateTracker::start($this->reportId, 'build_report', $stepRing);
        $report = Report::query()->findOrFail($this->reportId);

        try {
            if ($this->final) {
                $builder->rebuild($report, final: true);
                Cache::forget('report:'.$this->reportId.':articles');
                Cache::forget('report:'.$this->reportId.':extract_offset');
                JobStateTracker::done($this->reportId, 'build_report', $stepRing, ['final' => true]);

                return;
            }

            $builder->rebuild($report, final: false);
            $report->status = Report::STATUS_PROCESSING_OUTER;
            $report->save();
            JobStateTracker::done($this->reportId, 'build_report', $stepRing, ['partial' => true]);

            // Continue extracting remaining articles for outer rings (same 10 km corpus).
            ExtractIncidentsJob::dispatch($this->reportId, 'outer');
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'build_report', $e->getMessage(), $stepRing);
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }
}
