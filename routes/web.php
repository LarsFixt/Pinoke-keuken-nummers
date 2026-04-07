<?php

use App\Livewire\KitchenNumpad;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::display')->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// The kitchen protected screen
Route::middleware(['auth'])->group(function () {
    Route::get('/kitchen', KitchenNumpad::class)->name('kitchen.numpad');
});

Route::livewire('/kitchen2', 'pages::kitchen')->name('kitchen');

require __DIR__.'/settings.php';
