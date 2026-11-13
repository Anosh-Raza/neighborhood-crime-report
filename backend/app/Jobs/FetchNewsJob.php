<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\News\GoogleNewsAreaProvider;
use App\Services\News\KeywordMentionAnalyzer;
use App\Services\News\NewsSearchService;
use App\Services\Places\AreaKeywordService;
use App\Support\JobStateTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class FetchNewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public string $reportId, public string $ring = '0-1')
    {
    }

    public function handle(
        AreaKeywordService $areas,
        GoogleNewsAreaProvider $googleNews,
        KeywordMentionAnalyzer $analyzer,
        NewsSearchService $legacyNews,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        JobStateTracker::start($this->reportId, 'fetch_news', $this->ring);
        $report = Report::query()->findOrFail($this->reportId);

        try {
            $area = $areas->resolve(
                $report->listing_url,
                $report->address,
                $report->city,
                $report->country_code,
            );

            $report->area_keyword = $area['primary'];
            $report->area_aliases = $area['aliases'];
            $report->area_meta = $area;
            $report->save();

            $limit = min(20, (int) config('crime_report.news_max_articles_per_report', 20));

            $articles = $googleNews->search(
                $area['search_terms'],
                $report->country_code,
                $limit
            );

            // Skip slow GDELT/legacy RSS by default — area RSS is enough.
            if ($articles === [] && config('crime_report.news_legacy_fallback', false)) {
                $articles = $legacyNews->searchForReport(
                    $report->place_vocabulary ?? [],
                    $report->country_code,
                    $report->city,
                    min(8, $limit)
                );
            }

            $analysis = $analyzer->analyzeMany($articles, $area['search_terms']);

            $report->news_mentions = [
                'primary_keyword' => $area['primary'],
                'search_terms' => $area['search_terms'],
                'totals' => $analysis['totals'],
                'articles' => $analysis['articles'],
                'news_query' => $area['primary'],
            ];
            $report->save();

            Cache::put('report:'.$this->reportId.':articles', $articles, now()->addHours(6));
            Cache::put('report:'.$this->reportId.':extract_offset', 0, now()->addHours(6));
            JobStateTracker::done($this->reportId, 'fetch_news', $this->ring, [
                'count' => count($articles),
                'keyword' => $area['primary'],
                'mention_articles' => $analysis['totals']['article_count'] ?? 0,
                'crime' => $analysis['totals']['crime_count'] ?? 0,
            ]);
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
