<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · BarberFlow</title>
    @vite('resources/js/pages.js')
</head>
{{--
    Telas sem o menu: login, cadastro, confirmação de e-mail, esqueci e redefinir a senha, e as
    páginas de erro. Cada tela define o title, que também vira o título do cartão, e o content.
    Sem style="" no HTML: a CSP bloqueia estilo inline (as classes próprias ficam em resources/css/pages.css).
--}}
<body class="bg-body-tertiary">
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5" id="conteudo">
        <div class="guest-card w-100">
            <div class="text-center mb-4">
                <p class="h3 fw-semibold mb-1">BarberFlow</p>
                <p class="text-body-secondary mb-0">Gestão e agendamento para barbearias</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 mb-3">@yield('title')</h1>

                    @if (session('status'))
                        <div class="alert alert-success" role="status">{{ session('status') }}</div>
                    @endif

                    {{-- O que não deu certo e a pessoa precisa saber, como o e-mail de confirmação que não saiu. --}}
                    @if (session('warning'))
                        <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
                    @endif

                    @yield('content')
                </div>
            </div>

            @hasSection('links')
                <div class="d-flex flex-wrap justify-content-center gap-3 small mt-3">
                    @yield('links')
                </div>
            @endif
        </div>
    </main>
</body>
</html>
