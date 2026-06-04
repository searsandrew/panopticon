<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/', 'pages::admin.index')->name('index');
    });

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::livewire('/', 'pages::customers.index')->name('index');
        Route::livewire('/{accountNumber}', 'pages::customers.show')->name('show');
    });
});

require __DIR__.'/settings.php';
