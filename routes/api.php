<?php

use App\Http\Controllers\BarberController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ServiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Fica fora do limite de requisições: o throttleApi() do bootstrap/app.php, quando ligado, põe 'throttle:api'
// no grupo api, e o monitoramento e o deploy não podem receber 429. Se o limitador mudar de nome, mude aqui também.
Route::get('/health', HealthController::class)->name('health')->withoutMiddleware('throttle:api');

Route::apiResource('services', ServiceController::class);
Route::apiResource('barbers', BarberController::class);
Route::apiResource('clients', ClientController::class);
