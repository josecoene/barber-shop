@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    @include('partials.crud', [
        'resource' => 'clients',
        'title' => 'Clientes',
        'singular' => 'cliente',
        'plural' => 'clientes',
        'searchLabel' => 'Buscar por nome ou e-mail',
        'columns' => ['Nome', 'E-mail', 'Telefone'],
        'fields' => 'partials.person-fields',
    ])
@endsection
