<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
