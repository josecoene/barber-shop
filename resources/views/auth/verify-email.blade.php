@extends('layouts.guest')

@section('title', 'Confirme seu e-mail')

@section('content')
    {{--
        O texto fixo não diz que o link foi enviado: a tela abre de novo ao recarregar, ao entrar de novo ou
        ao tentar uma tela do cliente, quando não se sabe se o último envio deu certo. Quem diz que o link saiu
        é o aviso verde (status) do cadastro e do reenvio que deram certo; que ele não saiu, o amarelo (warning).
    --}}
    @if (session('warning'))
        <p>
            Falta confirmar o e-mail <strong class="text-break">{{ auth()->user()->email }}</strong>.
            Peça o link pelo botão abaixo e, quando ele chegar, clique nele para começar a usar a sua conta.
        </p>
        <p class="small text-body-secondary">
            Cada link vale por {{ config('auth.verification.expire', 60) }} minutos.
        </p>
    @else
        <p>
            Falta confirmar o e-mail <strong class="text-break">{{ auth()->user()->email }}</strong>.
            Clique no link de confirmação que vai para esse endereço para começar a usar a sua conta.
        </p>
        <p class="small text-body-secondary">
            Não recebeu o link? Confira a caixa de spam ou peça um novo. Cada link vale por
            {{ config('auth.verification.expire', 60) }} minutos.
        </p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mb-2">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Reenviar o link</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary w-100">Sair</button>
    </form>
@endsection
