<?php

use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

/*
 * Internal ingestion API.
 *
 * Used by the acquisition service on the same host. Authenticated with a Sanctum
 * token carrying the 'ingest' ability, so a leaked token cannot read history or
 * change configuration - it can only submit measurements.
 */
Route::prefix('internal/v1/ingest')
    ->middleware(['auth:sanctum', 'ability:ingest'])
    ->group(function (): void {
        Route::post('/batch', [IngestController::class, 'batch'])->name('ingest.batch');
        Route::post('/profile', [IngestController::class, 'profile'])->name('ingest.profile');
        Route::get('/health', [IngestController::class, 'health'])->name('ingest.health');
    });
