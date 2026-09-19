<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\MensagemController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', ChatController::class)
    ->middleware(['auth'])
    ->name('dashboard');

Route::get('/mensagens', [MensagemController::class, 'index'])
    ->middleware(['auth'])
    ->name('mensagens.index');

Route::post('/mensagens', [MensagemController::class, 'store'])
    ->middleware(['auth'])
    ->name('mensagens.store');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
