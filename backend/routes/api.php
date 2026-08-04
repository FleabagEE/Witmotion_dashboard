<?php

use App\Http\Controllers\Api\AlarmActionController;
use App\Http\Controllers\Api\AlarmDefinitionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\ReadController;
use App\Http\Controllers\Api\SpectrumController;
use App\Http\Controllers\Api\TiltController;
use App\Http\Controllers\Api\UserController;
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
        Route::get('/spectrum', SpectrumController::class)->name('spectrum');
        Route::get('/tilt', TiltController::class)->name('tilt');
        Route::get('/alarms', [ReadController::class, 'alarms'])->name('alarms.index');

        // Readable by anyone who can read: an operator who cannot see the
        // threshold cannot judge whether an alarm matters.
        Route::get('/alarm-definitions', [AlarmDefinitionController::class, 'index'])
            ->name('alarm-definitions.index');
    });

    // Acknowledgement is an operational act, not a read. A kiosk screen in a
    // public corridor must not be able to silence an alarm.
    Route::middleware(['auth:sanctum', 'ability:acknowledge'])->group(function (): void {
        Route::post('/alarms/{alarm}/acknowledge', [AlarmActionController::class, 'acknowledge'])
            ->name('alarms.acknowledge');
    });

    /*
     * Administration. Only the administrator role carries `administer`.
     *
     * Changing a threshold silences or raises an alarm and leaves the dashboard
     * looking healthy either way, so it sits behind the highest ability the
     * appliance has - above `configure`, which an engineer holds. An operator
     * can acknowledge what happened; only an administrator can change what
     * counts as happening.
     */
    Route::middleware(['auth:sanctum', 'ability:administer'])->group(function (): void {
        Route::patch('/alarm-definitions/{definition}', [AlarmDefinitionController::class, 'update'])
            ->name('alarm-definitions.update');
        Route::post('/alarm-definitions/{definition}/confirm', [AlarmDefinitionController::class, 'confirm'])
            ->name('alarm-definitions.confirm');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])
            ->name('users.password');
        Route::get('/roles', [UserController::class, 'roles'])->name('roles.index');
    });
});
