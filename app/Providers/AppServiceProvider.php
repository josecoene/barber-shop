<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureGates();
        $this->configureRateLimiting();
        $this->configureTrustedProxies();
        $this->configureUrls();

        // Senha de conta: no mínimo 8 caracteres (cadastro, troca e redefinição).
        Password::defaults(fn () => Password::min(8));
    }

    /**
     * Quem abre cada tela (middleware can: em routes/web.php e @can nas views). As
     * regras de cada recurso da API ficam nas Policies de app/Policies. O
     * dashboard não tem model, então a regra dele é um Gate (view-dashboard),
     * que vale para a API e para a tela.
     */
    private function configureGates(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());
        Gate::define('access-staff', fn (User $user): bool => $user->isAdmin() || $user->isBarber());
        Gate::define('access-client', fn (User $user): bool => $user->isClient());
        Gate::define('view-dashboard', fn (User $user): bool => $user->isAdmin());
    }

    /**
     * Limitadores usados pelo middleware throttle (valores em config/barberflow.php).
     * Passou do limite, a resposta é 429 com Retry-After.
     */
    private function configureRateLimiting(): void
    {
        // Toda a API (throttleApi() no bootstrap/app.php), por usuário logado ou, sem login, por IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('barberflow.rate_limits.api'))
            ->by($request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        // Login (telas e API) por IP, com qualquer e-mail. As senhas erradas de um mesmo e-mail
        // têm outro limite, menor, no App\Services\Auth\LoginAttempt.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('barberflow.rate_limits.login_per_ip'))
            ->by($request->ip()));

        // Cadastro de conta de cliente, por IP: cada cadastro manda um e-mail.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(config('barberflow.rate_limits.register'))
            ->by($request->ip()));

        // "Esqueci minha senha" e redefinição, por e-mail + IP. O limitador roda antes da validação,
        // então o e-mail ainda pode não ser texto (ex.: email[]=x).
        RateLimiter::for('password-reset', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(config('barberflow.rate_limits.password_reset'))
                ->by((is_string($email) ? Str::lower($email) : '').'|'.$request->ip());
        });

        // "Enviar lembrete agora", por usuário (a rota fica dentro do auth:sanctum): cada envio manda um
        // e-mail ao cliente, então uma conta não dispara lembretes em série para vários agendamentos. O
        // limite de um envio a cada 10 minutos por agendamento fica no App\Services\ReminderSender.
        RateLimiter::for('reminders', fn (Request $request) => Limit::perMinute(config('barberflow.rate_limits.reminders'))
            ->by('user:'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * Proxies confiáveis (ex.: o ALB), de TRUSTED_PROXIES. Com eles, o IP do
     * visitante (limites por IP) e o HTTPS (cookie seguro, HSTS) vêm do
     * X-Forwarded-For e do X-Forwarded-Proto. Sem eles, o Laravel ignora esses
     * cabeçalhos, que qualquer um pode inventar.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = config('barberflow.security.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies);
        }
    }

    /**
     * Fora do local e dos testes, os links que o app monta (os dos e-mails de
     * confirmação e de "esqueci minha senha", os redirecionamentos e os das telas)
     * começam sempre pelo APP_URL, nunca pelo Host da requisição. O trustHosts
     * aceita os IPs privados, por causa do health check do ALB: sem isto, quem
     * pedisse o e-mail de outra pessoa com "Host: 192.168.0.23" (ou com
     * X-Forwarded-Host, atrás de um proxy confiável) faria o link levar o token
     * para o endereço dele. No local fica o Host da requisição, para quem abre o
     * app por 127.0.0.1 ou por outra porta.
     *
     * Com APP_URL em https atrás do ALB, defina TRUSTED_PROXIES: o link de
     * confirmação é assinado com o endereço https, e o app só vê o https pelo
     * X-Forwarded-Proto do proxy confiável.
     */
    private function configureUrls(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        $appUrl = (string) config('app.url');

        URL::forceRootUrl($appUrl);

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
