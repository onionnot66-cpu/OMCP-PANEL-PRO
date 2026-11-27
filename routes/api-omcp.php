<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Omcp\ConnectionController;

Route::post('/enroll', [ConnectionController::class, 'enroll'])->middleware('throttle:10,1');
Route::post('/heartbeat', [ConnectionController::class, 'heartbeat'])->middleware('throttle:120,1');
