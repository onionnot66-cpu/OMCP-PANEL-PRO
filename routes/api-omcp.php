<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Omcp\ConnectionController;

Route::post('/enroll', [ConnectionController::class, 'enroll'])->middleware('throttle:10,1');
Route::post('/heartbeat', [ConnectionController::class, 'heartbeat'])->middleware('throttle:120,1');

Route::post('/jobs/claim', [\Pterodactyl\Http\Controllers\Api\Omcp\JobController::class, 'claim'])->middleware('throttle:120,1');
Route::post('/jobs/renew', [\Pterodactyl\Http\Controllers\Api\Omcp\JobController::class, 'renew'])->middleware('throttle:120,1');
Route::post('/jobs/complete', [\Pterodactyl\Http\Controllers\Api\Omcp\JobController::class, 'complete'])->middleware('throttle:120,1');
