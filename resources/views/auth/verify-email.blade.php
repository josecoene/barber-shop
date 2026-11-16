@extends('layouts.guest')

@section('title', 'Confirme seu e-mail')

@section('content')
    <p>
        Enviamos um link de confirmação para <strong class="text-break">{{ auth()->user()->email }}</strong>.
        Abra o e-mail e clique no link para começar a usar a sua conta.
    </p>
    <p class="small text-body-secondary">
        Não chegou? Confira a caixa de spam ou peça um link novo. Cada link vale por
        {{ config('auth.verification.expire', 60) }} minutos.
    </p>

    <form method="POST" action="{{ route('verification.send') }}" class="mb-2">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Reenviar o link</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary w-100">Sair</button>
    </form>
@endsection
