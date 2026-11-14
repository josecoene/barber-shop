<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

// Visitante: login, cadastro de cliente e "esqueci minha senha". Logado, o guest manda para a home.
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:register')->name('register.store');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // Confirmação de e-mail. O link do e-mail é assinado e só vale para o usuário logado.
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Telas do cliente: só depois de confirmar o e-mail. Minha conta vale para todos os perfis.
    Route::middleware('verified')->group(function () {
        Route::view('/account', 'account.show')->name('account.show');

        Route::middleware('can:access-client')->group(function () {
            Route::view('/booking', 'appointments.booking')->name('booking.create');
            Route::view('/my-appointments', 'appointments.mine')->name('my-appointments.index');
        });
    });

    Route::middleware('can:access-admin')->group(function () {
        Route::view('/barbers', 'barbers.index')->name('barbers.index');
        Route::view('/services', 'services.index')->name('services.index');
    });

    Route::middleware('can:access-staff')->group(function () {
        Route::view('/clients', 'clients.index')->name('clients.index');
        Route::view('/appointments', 'appointments.index')->name('appointments.index');
        Route::view('/appointments/create', 'appointments.create')->name('appointments.create');
    });
});
