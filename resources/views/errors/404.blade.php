@extends('layouts.guest')

@section('title', 'Página não encontrada')

@section('content')
    <p class="small text-body-secondary mb-2">Erro 404</p>
    <p>O endereço que você abriu não existe ou foi removido.</p>

    <a class="btn btn-primary w-100" href="{{ url('/') }}">Voltar para o início</a>
@endsection
