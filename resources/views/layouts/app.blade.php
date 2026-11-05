<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · BarberFlow</title>
    @vite('resources/js/pages.js')
</head>
<body class="bg-body-tertiary">
    @php
        $links = [
            ['route' => 'appointments.index', 'label' => 'Agenda'],
            ['route' => 'appointments.create', 'label' => 'Agendar'],
            ['route' => 'barbers.index', 'label' => 'Barbeiros'],
            ['route' => 'clients.index', 'label' => 'Clientes'],
            ['route' => 'services.index', 'label' => 'Serviços'],
        ];
    @endphp

    <nav class="navbar navbar-expand-lg bg-dark" data-bs-theme="dark">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('appointments.index') }}">BarberFlow</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-nav"
                aria-controls="main-nav" aria-expanded="false" aria-label="Abrir menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="main-nav">
                <ul class="navbar-nav me-auto">
                    @foreach ($links as $link)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs($link['route']) ? 'active' : '' }}"
                                @if (request()->routeIs($link['route'])) aria-current="page" @endif
                                href="{{ route($link['route']) }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
                <a class="btn btn-outline-light btn-sm" href="{{ route('login') }}">Sair</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        @yield('content')
    </main>
</body>
</html>
