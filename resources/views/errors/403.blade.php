@extends('layouts.guest')

@section('title', 'Acesso negado')

@section('content')
    <p class="small text-body-secondary mb-2">Erro 403</p>
    <p>Você não tem permissão para abrir esta página.</p>

    <a class="btn btn-primary w-100" href="{{ url('/') }}">Voltar para o início</a>
@endsection
