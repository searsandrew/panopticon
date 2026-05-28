<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');
    Route::livewire('/customers/{customer}', 'pages::customers.show')->name('customers.show');
});

require __DIR__.'/settings.php';
