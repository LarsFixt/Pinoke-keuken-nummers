<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\KioskTvController;
use App\Http\Controllers\Api\KitchenTicketController;
use App\Http\Middleware\VerifyKioskToken;
use App\Http\Middleware\VerifyTicketBridgeSignature;
use Illuminate\Support\Facades\Route;

Route::get('/ads', AdController::class);
Route::middleware(['throttle:30,1', VerifyKioskToken::class])->prefix('kiosk')->group(function () {
    Route::get('/tv-status', [KioskTvController::class, 'getStatus']);
    Route::post('/heartbeat', [KioskTvController::class, 'heartbeat'])->name('kiosk.heartbeat');
});

Route::middleware(['throttle:120,1', VerifyTicketBridgeSignature::class])->prefix('kitchen-tickets')->group(function () {
    Route::post('/', [KitchenTicketController::class, 'store'])->name('kitchen-tickets.store');
    Route::post('/heartbeat', [KitchenTicketController::class, 'heartbeat'])->name('kitchen-tickets.heartbeat');
});
