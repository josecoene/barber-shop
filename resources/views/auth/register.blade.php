@extends('layouts.guest')

@section('title', 'Criar conta')

@section('content')
    <p class="text-body-secondary">
        Conta para clientes da barbearia. Depois do cadastro, enviamos um link para confirmar o seu e-mail.
    </p>
    <p class="small text-body-secondary">Campos com <span class="text-danger">*</span> são obrigatórios.</p>

    <form method="POST" action="{{ route('register.store') }}" novalidate>
        @csrf

        @include('partials.form-field', [
            'id' => 'register-name',
            'name' => 'name',
            'label' => 'Nome',
            'autocomplete' => 'name',
            'maxlength' => 255,
            'required' => true,
            'value' => old('name'),
        ])

        @include('partials.form-field', [
            'id' => 'register-email',
            'name' => 'email',
            'label' => 'E-mail',
            'type' => 'email',
            'autocomplete' => 'email',
            'maxlength' => 255,
            'required' => true,
            'value' => old('email'),
        ])

        @include('partials.form-field', [
            'id' => 'register-phone',
            'name' => 'phone',
            'label' => 'Telefone',
            'type' => 'tel',
            'autocomplete' => 'tel',
            'maxlength' => 20,
            'value' => old('phone'),
        ])

        @include('partials.form-field', [
            'id' => 'register-password',
            'name' => 'password',
            'label' => 'Senha',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
            'hint' => 'Mínimo de 8 caracteres.',
        ])

        @include('partials.form-field', [
            'id' => 'register-password-confirmation',
            'name' => 'password_confirmation',
            'label' => 'Confirme a senha',
            'type' => 'password',
            'autocomplete' => 'new-password',
            'required' => true,
        ])

        <button type="submit" class="btn btn-primary w-100">Criar conta</button>
    </form>
@endsection

@section('links')
    <span class="text-body-secondary">Já tem conta? <a href="{{ route('login') }}">Entrar</a></span>
@endsection
