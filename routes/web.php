<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HostController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PlayController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/enter', [HomeController::class, 'enter'])->name('enter');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale');

Route::prefix('host/{room}')->name('host.')->scopeBindings()->group(function () {
    Route::get('/', [HostController::class, 'show'])->name('show');
    Route::post('/photos', [HostController::class, 'upload'])->name('photos.upload');
    Route::delete('/photos/{photo}', [HostController::class, 'destroyPhoto'])->name('photos.destroy');
    Route::post('/photos/{photo}/activate', [HostController::class, 'activate'])->name('photos.activate');
    Route::post('/photos/{photo}/words', [HostController::class, 'storeWord'])->name('words.store');
    Route::delete('/photos/{photo}/words/{word}', [HostController::class, 'destroyWord'])->name('words.destroy');
});

Route::prefix('play/{room}')->name('play.')->group(function () {
    Route::get('/', [PlayController::class, 'show'])->name('show');
    Route::get('/state', [PlayController::class, 'state'])->name('state');
    Route::post('/start', [PlayController::class, 'start'])->name('start');
    Route::post('/transcribe', [PlayController::class, 'transcribe'])->name('transcribe');
    Route::post('/submit', [PlayController::class, 'submit'])->name('submit');
    Route::post('/generate', [PlayController::class, 'generate'])->name('generate');
    Route::post('/reset', [PlayController::class, 'reset'])->name('reset');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'loginForm'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->name('login.submit');
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');

    Route::middleware('admin')->group(function () {
        Route::get('/rooms', [AdminController::class, 'rooms'])->name('rooms');
        Route::post('/rooms', [AdminController::class, 'storeRoom'])->name('rooms.store');
        Route::delete('/rooms/{room}', [AdminController::class, 'destroyRoom'])->name('rooms.destroy');
    });
});
