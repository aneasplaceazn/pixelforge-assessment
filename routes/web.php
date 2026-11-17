<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GenerationController::class, 'index'])->name('dashboard');
    Route::post('generation-estimates', GenerationEstimateController::class)->middleware('throttle:30,1')->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:10,1')->name('generations.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->middleware('throttle:5,1')->name('generations.retry');
});

require __DIR__.'/settings.php';
