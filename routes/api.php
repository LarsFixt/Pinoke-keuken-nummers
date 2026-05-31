<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdController;
use Illuminate\Support\Facades\Route;

Route::get('/ads', AdController::class);
