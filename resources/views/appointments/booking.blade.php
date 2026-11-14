@extends('layouts.app')

@section('title', 'Agendar')

@section('content')
    {{-- Agendar do cliente: ele agenda para si (a API usa o cadastro ligado à conta), sem campo de cliente. --}}
    @include('partials.appointment-form', [
        'mode' => 'client',
        'heading' => 'Agendar horário',
        'backUrl' => route('my-appointments.index'),
        'backLabel' => 'Voltar para Meus horários',
        'successUrl' => route('my-appointments.index'),
    ])
@endsection
