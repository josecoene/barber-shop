@extends('layouts.guest')

@section('title', 'Sessão expirada')

@section('content')
    <p class="small text-body-secondary mb-2">Erro 419</p>
    <p>A página ficou aberta por muito tempo e a sua sessão expirou. Volte e tente de novo.</p>

    <a class="btn btn-primary w-100" href="{{ url('/') }}">Voltar para o início</a>
@endsection
