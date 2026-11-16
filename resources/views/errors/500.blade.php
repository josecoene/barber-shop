@extends('layouts.guest')

@section('title', 'Erro no servidor')

@section('content')
    <p class="small text-body-secondary mb-2">Erro 500</p>
    <p>Algo deu errado do nosso lado. Tente de novo em alguns minutos.</p>

    <a class="btn btn-primary w-100" href="{{ url('/') }}">Voltar para o início</a>
@endsection
