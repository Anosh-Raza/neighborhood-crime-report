<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Extraction\ArticleExtractionService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ExtractIncidentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 55;

    public function __construct(public string $reportId, public string $ring = '0-1')
    {
    }

    public function handle(ArticleExtractionService $extractor): void
    {
        JobStateTracker::start($this->reportId, 'extract_incidents', $this->ring);
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $articles = Cache::get('report:'.$this->reportId.':articles', []);
            $offsetKey = 'report:'.$this->reportId.':extract_offset';
            $offset = (int) Cache::get($offsetKey, 0);
            $batchSize = 8;
            $batch = array_slice($articles, $offset, $batchSize);

            $propertyContext = [
                'address' => $report->address,
                'city' => $report->city,
                'country' => $report->country,
                'lat' => $report->latitude(),
                'lng' => $report->longitude(),
            ];

            $raw = [];
            foreach ($batch as $article) {
                $raw = array_merge($raw, $extractor->extract($article, $this->reportId, $propertyContext));
            }

            Cache::put($offsetKey, $offset + count($batch), now()->addHours(6));
            Cache::put('report:'.$this->reportId.':raw_batch', $raw, now()->addHours(2));

            JobStateTracker::done($this->reportId, 'extract_incidents', $this->ring, [
                'offset' => $offset + count($batch),
                'batch' => count($batch),
            ]);

            MatchAndDedupeJob::dispatch(
                $this->reportId,
                $this->ring,
                $offset + count($batch) >= count($articles)
            );
        } catch (\Throwable $e) {
            JobStateTracker::fail($this->reportId, 'extract_incidents', $e->getMessage(), $this->ring);
            $report->status = Report::STATUS_FAILED;
            $report->error_message = $e->getMessage();
            $report->save();
            throw $e;
        }
    }
}
