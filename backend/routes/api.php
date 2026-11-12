<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\QueueDrainController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'crime-report-api']));

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

Route::post('/billing/webhook', [BillingController::class, 'webhook']);
// Vercel Cron uses GET; local/manual drains may POST with Bearer CRON_SECRET.
Route::match(['get', 'post'], '/internal/queue/drain', QueueDrainController::class);

// Open MVP: paste a listing URL and view reports without login/credits.
Route::post('/reports/preview', [ReportController::class, 'preview'])->middleware('throttle:30,1');
Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:20,1');
Route::get('/reports/{id}', [ReportController::class, 'show']);
Route::get('/reports/{id}/status', [ReportController::class, 'status']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::get('/billing/portal', [BillingController::class, 'portal']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::delete('/reports/{id}', [ReportController::class, 'destroy']);
});
