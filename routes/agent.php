<?php

use Illuminate\Support\Facades\Route;
use Platform\Signals\Http\Controllers\Api\AgentEnvironmentController;

/**
 * Agent-API des Signals-Moduls (Worker-Token, auth:api) – wie die Agent-API der Organisation.
 */
Route::middleware('auth:api')->prefix('signals/agent')->group(function () {
    Route::get('/environment', [AgentEnvironmentController::class, 'environment'])->name('signals.api.agent.environment');
});
