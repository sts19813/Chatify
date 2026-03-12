<?php

use App\Http\Controllers\ChatLandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureGuestUser;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BotChatController;

Route::get('/', [ChatLandingController::class, 'index'])->name('chat.landing');
Route::get('/chat/guest', [ChatLandingController::class, 'guest'])
    ->middleware(EnsureGuestUser::class)
    ->name('chat.guest');
Route::get('/chat/start/{agentId}', [ChatLandingController::class, 'start'])
    ->middleware(EnsureGuestUser::class)
    ->whereNumber('agentId')
    ->name('chat.start');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::post('/bot/send', [BotChatController::class, 'sendToBot'])
    ->middleware(EnsureGuestUser::class);

require __DIR__.'/auth.php';
