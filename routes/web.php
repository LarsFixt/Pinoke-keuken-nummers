<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::display')->name('home');
Route::livewire('/track', 'pages::track')->name('track');

// Route::view('dashboard', 'dashboard')
//     ->middleware(['auth', 'verified'])
//     ->name('dashboard');

// The kitchen protected screen
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::kitchen')->name('dashboard');
    Route::livewire('messages', 'pages::messages')->name('messages');
});

require __DIR__.'/settings.php';
