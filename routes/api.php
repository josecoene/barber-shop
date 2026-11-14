<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailableSlotController;
use App\Http\Controllers\BarberController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

// Rotas abertas, com limite próprio além do 'throttle:api' do grupo (limitadores no AppServiceProvider).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.email');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.store');

// Fica fora do limite de requisições: o throttleApi() do bootstrap/app.php põe 'throttle:api' no grupo
// api, e o monitoramento e o deploy não podem receber 429. Se o limitador mudar de nome, mude aqui também.
Route::get('/health', HealthController::class)->name('health')->withoutMiddleware('throttle:api');

Route::middleware('auth:sanctum')->group(function () {
    // A própria conta: vale também para o cliente que ainda não confirmou o e-mail.
    Route::get('/user', [AuthController::class, 'user'])->name('user');
    Route::put('/user/password', [AuthController::class, 'updatePassword'])->name('user.password');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // O resto só com o e-mail confirmado. Admin e barbeiro já nascem confirmados.
    Route::middleware('verified')->group(function () {
        Route::apiResource('services', ServiceController::class);
        Route::apiResource('barbers', BarberController::class);
        Route::apiResource('clients', ClientController::class);

        // Agenda, sem destroy: cancelar é mudar o status.
        Route::apiResource('appointments', AppointmentController::class)->except('destroy');
        Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
        Route::get('/barbers/{barber}/available-slots', AvailableSlotController::class)->name('barbers.available-slots');
    });
});
