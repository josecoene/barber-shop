@extends('layouts.guest')

@section('title', 'Sistema fora do ar')

@section('content')
    <p class="small text-body-secondary mb-2">Erro 503</p>
    <p>O BarberFlow está em manutenção ou fora do ar por alguns instantes. Tente de novo em alguns minutos.</p>

    {{-- href vazio abre de novo a página atual. Funciona também no php artisan down --render, que monta a página antes. --}}
    <a class="btn btn-primary w-100" href="">Tentar de novo</a>
@endsection
