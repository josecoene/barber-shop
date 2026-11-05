@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    @include('partials.crud', [
        'resource' => 'clients',
        'title' => 'Clientes',
        'singular' => 'cliente',
        'columns' => ['Nome', 'E-mail', 'Telefone'],
        'fields' => 'partials.person-fields',
    ])
@endsection
