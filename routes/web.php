<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RepositoryController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]{1,10}');
Route::pattern('bug', '[0-9]{1,10}');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::get('/register', fn () => view('auth', ['mode' => 'register']));
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/', [RepositoryController::class, 'index']);
    Route::post('/repositories', [RepositoryController::class, 'connect']);
    Route::post('/r/{id}/import', [RepositoryController::class, 'import']);
    Route::post('/r/{id}/members', [RepositoryController::class, 'member']);
    Route::post('/r/{id}/analysis', [RepositoryController::class, 'analyze']);
    Route::post('/r/{id}/bugs', [RepositoryController::class, 'report']);
    Route::post('/r/{id}/bugs/{bug}', [RepositoryController::class, 'event']);
    Route::get('/r/{id}/{tab?}', [RepositoryController::class, 'show']);
});
