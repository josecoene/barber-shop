<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- O pages.js manda este token no X-CSRF-TOKEN das chamadas à API, que usam o cookie da sessão. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Fuso da barbearia: as telas da agenda calculam "hoje" e "agora" nele, e não no fuso do navegador. --}}
    <meta name="app-timezone" content="{{ config('app.timezone') }}">
    <title>@yield('title') · BarberFlow</title>
    @vite('resources/js/pages.js')
</head>
<body class="bg-body-tertiary">
    @php
        // Quem está logado, para o nome e o perfil no menu.
        $currentUser = auth()->user();

        // Menu por perfil (Gates no AppServiceProvider):
        // - admin: Dashboard (a tela inicial dele), Agenda, Agendar, Pagamentos, Clientes, Barbeiros e Serviços;
        // - barbeiro: Agenda (a tela inicial dele), Agendar, Pagamentos e Clientes;
        // - cliente: Agendar, Meus horários (a tela inicial dele) e Minha conta.
        $links = [
            ['route' => 'dashboard', 'label' => 'Dashboard', 'ability' => 'access-admin'],
            ['route' => 'appointments.index', 'label' => 'Agenda', 'ability' => 'access-staff'],
            ['route' => 'appointments.create', 'label' => 'Agendar', 'ability' => 'access-staff'],
            ['route' => 'payments.index', 'label' => 'Pagamentos', 'ability' => 'access-staff'],
            ['route' => 'clients.index', 'label' => 'Clientes', 'ability' => 'access-staff'],
            ['route' => 'barbers.index', 'label' => 'Barbeiros', 'ability' => 'access-admin'],
            ['route' => 'services.index', 'label' => 'Serviços', 'ability' => 'access-admin'],
            ['route' => 'booking.create', 'label' => 'Agendar', 'ability' => 'access-client'],
            ['route' => 'my-appointments.index', 'label' => 'Meus horários', 'ability' => 'access-client'],
            ['route' => 'account.show', 'label' => 'Minha conta', 'ability' => 'access-client'],
        ];
    @endphp

    <a class="visually-hidden-focusable btn btn-light position-absolute top-0 start-0 m-2 z-3" href="#conteudo">Pular para o conteúdo</a>

    {{--
        O menu abre a partir de 1200px (navbar-expand-xl); abaixo disso, fica no botão "Abrir menu". Aberto,
        ele cabe numa linha só: os links não quebram (text-nowrap) e, quando falta espaço, quem encolhe é o
        nome do usuário, cortado com reticências (navbar-account no pages.css). Em 992px, os 7 links do admin
        com o perfil, Minha conta e Sair não cabem nem com o nome escondido.
    --}}
    <nav class="navbar navbar-expand-xl bg-dark" data-bs-theme="dark" aria-label="Menu principal">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('home') }}">BarberFlow</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-nav"
                aria-controls="main-nav" aria-expanded="false" aria-label="Abrir menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="main-nav">
                <ul class="navbar-nav me-auto">
                    @foreach ($links as $link)
                        @can($link['ability'])
                            <li class="nav-item">
                                <a class="nav-link text-nowrap {{ request()->routeIs($link['route']) ? 'active' : '' }}"
                                    @if (request()->routeIs($link['route'])) aria-current="page" @endif
                                    href="{{ route($link['route']) }}">{{ $link['label'] }}</a>
                            </li>
                        @endcan
                    @endforeach
                </ul>

                <div class="navbar-account d-flex flex-wrap flex-xl-nowrap align-items-center gap-2 gap-xl-3 py-2 py-xl-0 ms-xl-3">
                    {{-- O nome cortado na tela continua inteiro no title e para o leitor de tela. --}}
                    <span class="navbar-text d-flex align-items-center gap-1 py-0">
                        <span class="visually-hidden">Você entrou como</span>
                        <span class="navbar-user text-truncate text-white" title="{{ $currentUser->name }}">{{ $currentUser->name }}</span>
                        <span class="badge text-bg-secondary">{{ $currentUser->role?->label() }}</span>
                    </span>

                    {{-- Para o cliente, Minha conta já está no menu. --}}
                    @cannot('access-client')
                        <a class="link-light link-underline-opacity-0 link-underline-opacity-75-hover small text-nowrap"
                            @if (request()->routeIs('account.show')) aria-current="page" @endif
                            href="{{ route('account.show') }}">Minha conta</a>
                    @endcannot

                    {{-- Sair é um POST com o token CSRF: um link GET poderia ser disparado por outro site. --}}
                    <form method="POST" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4" id="conteudo" tabindex="-1">
        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
