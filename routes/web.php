<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::redirect('/dashboard', '/generations')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/new', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('/credits', [CreditController::class, 'show'])->name('credits.show');
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
});

Route::middleware(['auth', 'can:admin'])->get('/admin/report', AdminReportController::class)->name('admin.report');

require __DIR__.'/auth.php';
