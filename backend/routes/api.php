<?php

use App\Http\Controllers\Api\AlarmActionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\ReadController;
use Illuminate\Support\Facades\Route;

/*
 * Internal ingestion API — used by the acquisition service on the same host.
 * Its tokens carry the 'ingest' ability only, so a leaked appliance credential
 * cannot read history or change configuration.
 */
Route::prefix('internal/v1/ingest')
    ->middleware(['auth:sanctum', 'ability:ingest'])
    ->group(function (): void {
        Route::post('/batch', [IngestController::class, 'batch'])->name('ingest.batch');
        Route::post('/profile', [IngestController::class, 'profile'])->name('ingest.profile');
        Route::get('/health', [IngestController::class, 'health'])->name('ingest.health');
    });

/*
 * Dashboard API. Abilities, not roles, are what the routes check - see
 * App\Support\Roles for the mapping.
 */
Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'ability:read'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/overview', [ReadController::class, 'overview'])->name('overview');
        Route::get('/sensors', [ReadController::class, 'sensors'])->name('sensors.index');
        Route::get('/sensors/{sensorId}/channels', [ReadController::class, 'channels'])->name('sensors.channels');
        Route::get('/sensors/{sensorId}/latest', [ReadController::class, 'latest'])->name('sensors.latest');
        Route::get('/series', [ReadController::class, 'series'])->name('series');
        Route::get('/series/multi', [ReadController::class, 'multiSeries'])->name('series.multi');
        Route::get('/alarms', [ReadController::class, 'alarms'])->name('alarms.index');
    });

    // Acknowledgement is an operational act, not a read. A kiosk screen in a
    // public corridor must not be able to silence an alarm.
    Route::middleware(['auth:sanctum', 'ability:acknowledge'])->group(function (): void {
        Route::post('/alarms/{alarm}/acknowledge', [AlarmActionController::class, 'acknowledge'])
            ->name('alarms.acknowledge');
    });
});
