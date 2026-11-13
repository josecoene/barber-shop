@extends('layouts.guest')

@section('title', 'Redefinir senha')

@section('content')
    {{-- Aberta pelo link do e-mail (/reset-password/{token}?email=...). O token vai escondido no formulário. --}}
    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @include('partials.form-field', [
            'id' => 'reset-email',
            'name' => 'email',
            'label' => 'E-mail',
            'type' => 'email',
            'autocomplete' => 'email',
            'maxlength' => 255,
            'required' => true,
            'value' => old('email', $email),
        ])

        @include('partials.form-field', [
            'id' => 'reset-password',
            'name' => 'password',
            'label' => 'Senha nova',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
            'hint' => 'Mínimo de 8 caracteres.',
        ])

        @include('partials.form-field', [
            'id' => 'reset-password-confirmation',
            'name' => 'password_confirmation',
            'label' => 'Confirme a senha nova',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
        ])

        <button type="submit" class="btn btn-primary w-100">Redefinir senha</button>
    </form>
@endsection

@section('links')
    <a href="{{ route('password.request') }}">Pedir um link novo</a>
    <a href="{{ route('login') }}">Voltar para o login</a>
@endsection
