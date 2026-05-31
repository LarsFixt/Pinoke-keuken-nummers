<?php

use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\IsSuperAdmin;
use App\Livewire\Admin\Sponsors\Index as SponsorsIndex;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::display')->name('home');
Route::livewire('/track', 'pages::track')->name('track');

// Route::view('dashboard', 'dashboard')
//     ->middleware(['auth', 'verified'])
//     ->name('dashboard');

// The kitchen protected screen
Route::middleware(['auth', 'verified', IsAdmin::class])->group(function () {
    Route::livewire('dashboard', 'pages::kitchen')->name('dashboard');
    Route::livewire('messages', 'pages::messages')->name('messages');
});

Route::middleware(['auth', 'verified', IsSuperAdmin::class])->group(function () {
    Route::livewire('users', 'pages::users')->name('users');
    Route::livewire('sponsors', SponsorsIndex::class)->name('sponsors.index');
});

require __DIR__.'/settings.php';
