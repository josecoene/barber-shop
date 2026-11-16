@extends('layouts.app')

@section('title', 'Barbeiros')

@section('content')
    @include('partials.crud', [
        'resource' => 'barbers',
        'title' => 'Barbeiros',
        'singular' => 'barbeiro',
        'plural' => 'barbeiros',
        'searchLabel' => 'Buscar por nome ou e-mail',
        'columns' => ['Nome', 'E-mail', 'Telefone', 'Acesso'],
        'fields' => 'partials.barber-fields',
    ])
@endsection
