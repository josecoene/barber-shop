<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/login', 'auth.login')->name('login');
Route::view('/barbers', 'barbers.index')->name('barbers.index');
Route::view('/clients', 'clients.index')->name('clients.index');
Route::view('/services', 'services.index')->name('services.index');
Route::view('/appointments', 'appointments.index')->name('appointments.index');
Route::view('/appointments/create', 'appointments.create')->name('appointments.create');
