<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\KioskTvController;
use Illuminate\Support\Facades\Route;

Route::get('/ads', AdController::class);
Route::get('/kiosk/tv-status', [KioskTvController::class, 'getStatus']);
