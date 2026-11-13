@extends('layouts.guest')

@section('title', 'Muitas tentativas')

@section('content')
    @php
        // O limite de requisições manda no Retry-After quantos segundos faltam.
        $seconds = isset($exception) ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0;
    @endphp

    <p class="small text-body-secondary mb-2">Erro 429</p>
    <p class="mb-1">Você fez muitas tentativas em pouco tempo.</p>
    <p>
        @if ($seconds > 0)
            Tente de novo em {{ $seconds }} segundos.
        @else
            Aguarde um minuto e tente de novo.
        @endif
    </p>

    <a class="btn btn-primary w-100" href="{{ url('/') }}">Voltar para o início</a>
@endsection
