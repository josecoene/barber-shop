@extends('layouts.app')

@section('title', 'Serviços')

@section('content')
    @include('partials.crud', [
        'resource' => 'services',
        'title' => 'Serviços',
        'singular' => 'serviço',
        'columns' => ['Nome', 'Duração', 'Preço', 'Status'],
        'fields' => 'partials.service-fields',
    ])
@endsection
