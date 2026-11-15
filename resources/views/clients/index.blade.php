@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    @include('partials.crud', [
        'resource' => 'clients',
        'title' => 'Clientes',
        'singular' => 'cliente',
        'plural' => 'clientes',
        'searchLabel' => 'Buscar por nome ou e-mail',
        // Fidelidade: visitas desde o último prêmio (ex.: 7/10) e o selo do prêmio, que vêm em cada cliente do GET /api/clients.
        'columns' => ['Nome', 'E-mail', 'Telefone', 'Fidelidade'],
        'fields' => 'partials.person-fields',
        // Só o admin exclui clientes (ClientPolicy::delete): para o barbeiro, a tabela fica sem o botão Excluir.
        'canDelete' => Gate::allows('access-admin'),
    ])
@endsection
