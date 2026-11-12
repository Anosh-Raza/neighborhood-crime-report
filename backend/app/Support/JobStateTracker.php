<?php

namespace App\Support;

use App\Models\JobState;
use Carbon\Carbon;

class JobStateTracker
{
    public static function start(string $reportId, string $step, ?string $ring = null): JobState
    {
        $state = JobState::query()->firstOrNew([
            'report_id' => $reportId,
            'step' => $step,
            'ring' => $ring,
        ]);

        $state->status = JobState::STATUS_RUNNING;
        $state->attempts = ((int) $state->attempts) + 1;
        $state->error = null;
        $state->updated_at = Carbon::now();
        $state->save();

        return $state;
    }

    public static function done(string $reportId, string $step, ?string $ring = null, array $meta = []): void
    {
        JobState::query()->updateOrCreate(
            [
                'report_id' => $reportId,
                'step' => $step,
                'ring' => $ring,
            ],
            [
                'status' => JobState::STATUS_DONE,
                'error' => null,
                'meta' => $meta,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    public static function fail(string $reportId, string $step, string $error, ?string $ring = null): void
    {
        JobState::query()->updateOrCreate(
            [
                'report_id' => $reportId,
                'step' => $step,
                'ring' => $ring,
            ],
            [
                'status' => JobState::STATUS_FAILED,
                'error' => mb_substr($error, 0, 2000),
                'updated_at' => Carbon::now(),
            ]
        );
    }
}
