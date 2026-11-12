<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class QueueDrainController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('app.cron_secret', env('CRON_SECRET'));
        $token = $request->bearerToken() ?: $request->header('x-cron-secret');
        $vercelCron = $request->headers->has('x-vercel-cron');
        $authorized = $secret && (
            ($token && hash_equals($secret, (string) $token))
            || $vercelCron
        );
        if (! $authorized) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $exit = Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--sleep' => 0,
            '--tries' => 2,
        ]);

        return response()->json([
            'ok' => $exit === 0,
            'output' => Artisan::output(),
        ]);
    }
}
