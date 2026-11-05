<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · BarberFlow</title>
    @vite('resources/js/pages.js')
</head>
<body class="bg-body-tertiary">
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-5" data-login>
        <div class="w-100" style="max-width: 400px;">
            <div class="text-center mb-4">
                <h1 class="h3 fw-semibold mb-1">BarberFlow</h1>
                <p class="text-body-secondary mb-0">Gestão e agendamento para barbearias</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Entrar</h2>

                    <div data-alerts></div>

                    <form novalidate data-login-form>
                        <div class="mb-3">
                            <label for="login-email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="login-email" name="email"
                                autocomplete="email" required>
                            <div class="invalid-feedback">Informe um e-mail válido.</div>
                        </div>

                        <div class="mb-3">
                            <label for="login-password" class="form-label">Senha</label>
                            <input type="password" class="form-control" id="login-password" name="password"
                                autocomplete="current-password" required>
                            <div class="invalid-feedback">Informe a senha.</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>
                </div>
            </div>

            <p class="text-center small text-body-secondary mt-3 mb-0">
                O login ainda não funciona.
                <a href="{{ route('appointments.index') }}">Continuar sem login</a>
            </p>
        </div>
    </main>
</body>
</html>
