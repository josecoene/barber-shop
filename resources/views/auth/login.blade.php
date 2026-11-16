@extends('layouts.guest')

@section('title', 'Entrar')

@section('content')
    {{-- ?expirou=1: o pages.js manda para cá quando a API responde 401 ou 419 (sessão expirada). --}}
    @if (request()->boolean('expirou'))
        <div class="alert alert-warning" role="status">Sua sessão expirou. Entre de novo para continuar.</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf

        @include('partials.form-field', [
            'id' => 'login-email',
            'name' => 'email',
            'label' => 'E-mail',
            'type' => 'email',
            'autocomplete' => 'email',
            'maxlength' => 255,
            'required' => true,
            'value' => old('email'),
        ])

        @include('partials.form-field', [
            'id' => 'login-password',
            'name' => 'password',
            'label' => 'Senha',
            'type' => 'password',
            'autocomplete' => 'current-password',
            'required' => true,
        ])

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="login-remember" name="remember" value="1" @checked(old('remember'))>
            <label class="form-check-label" for="login-remember">Lembrar de mim</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">Entrar</button>
    </form>
@endsection

@section('links')
    <a href="{{ route('password.request') }}">Esqueci minha senha</a>
    <a href="{{ route('register') }}">Criar conta</a>
@endsection
