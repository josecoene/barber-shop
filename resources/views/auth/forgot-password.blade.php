@extends('layouts.guest')

@section('title', 'Esqueci minha senha')

@section('content')
    <p class="text-body-secondary">
        Informe o e-mail da sua conta. Se ele estiver cadastrado, enviamos um link para você criar uma senha nova.
    </p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        @include('partials.form-field', [
            'id' => 'forgot-email',
            'name' => 'email',
            'label' => 'E-mail',
            'type' => 'email',
            'autocomplete' => 'email',
            'maxlength' => 255,
            'required' => true,
            'value' => old('email'),
        ])

        <button type="submit" class="btn btn-primary w-100">Enviar o link</button>
    </form>
@endsection

@section('links')
    <a href="{{ route('login') }}">Voltar para o login</a>
@endsection
