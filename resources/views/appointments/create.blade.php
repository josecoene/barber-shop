@extends('layouts.app')

@section('title', 'Agendar')

@section('content')
    {{-- Agendar da barbearia (admin e barbeiro). Com ?appointment={id}, remarca; com ?date=, já abre no dia. --}}
    @include('partials.appointment-form', [
        'mode' => 'staff',
        'heading' => 'Novo agendamento',
        'backUrl' => route('appointments.index'),
        'backLabel' => 'Voltar para a agenda',
        'successUrl' => route('appointments.index'),
    ])
@endsection
