@extends('layouts.app')

@section('title', 'Barbeiros')

@section('content')
    @include('partials.crud', [
        'resource' => 'barbers',
        'title' => 'Barbeiros',
        'singular' => 'barbeiro',
        'columns' => ['Nome', 'E-mail', 'Telefone'],
        'fields' => 'partials.person-fields',
    ])
@endsection
