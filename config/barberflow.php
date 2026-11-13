<?php

/*
|--------------------------------------------------------------------------
| Configuração do BarberFlow
|--------------------------------------------------------------------------
|
| Tudo que o BarberFlow lê do ambiente passa por aqui. O código chama
| config('barberflow...'), nunca env(): com o php artisan optimize a
| configuração fica congelada em cache e o env() fora de config/ devolve null.
| Os testes mudam estes valores com config([...]).
|
| Variável vazia no .env conta como "não definida" e vale o padrão.
|
*/

$isBlank = static fn (mixed $value): bool => $value === null || $value === '';

$integer = static fn (string $key, int $default): int => $isBlank(env($key)) ? $default : (int) env($key);

$appUrl = $isBlank(env('APP_URL')) ? 'http://localhost' : env('APP_URL');

return [

    /*
    | Administrador inicial, criado pelo AdminUserSeeder. Sem e-mail ou sem
    | senha, o seeder só avisa e não cria nada.
    */

    'admin' => [
        'name' => $isBlank(env('ADMIN_NAME')) ? 'Administrador' : env('ADMIN_NAME'),
        'email' => $isBlank(env('ADMIN_EMAIL')) ? null : env('ADMIN_EMAIL'),
        'password' => $isBlank(env('ADMIN_PASSWORD')) ? null : env('ADMIN_PASSWORD'),
    ],

    /*
    | Limites de requisições por minuto. Passou do limite, a resposta é 429 com
    | Retry-After (no login errado, o 429 traz a mensagem no campo e-mail).
    */

    'rate_limits' => [
        // Toda a API, por usuário logado ou, sem login, por IP. O teste de carga sobe este valor.
        'api' => $integer('API_RATE_LIMIT', 120),

        // Senhas erradas no login, por e-mail + IP (telas e API).
        'login' => $integer('LOGIN_RATE_LIMIT', 5),

        // Tentativas de login por IP, com qualquer e-mail: barra quem testa muitas contas de uma vez.
        'login_per_ip' => $integer('LOGIN_IP_RATE_LIMIT', 20),

        // Cadastros de conta de cliente, por IP.
        'register' => 5,

        // Pedidos de "esqueci a senha" e redefinições, por e-mail + IP.
        'password_reset' => 5,
    ],

    'security' => [
        /*
        | Proxies cujo X-Forwarded-For e X-Forwarded-Proto a aplicação aceita
        | (ex.: a faixa da VPC onde fica o ALB, "10.0.0.0/16"), separados por
        | vírgula. "*" confia em quem chamar. Vazio, não confia em nenhum.
        */
        'trusted_proxies' => $isBlank(env('TRUSTED_PROXIES')) ? null : env('TRUSTED_PROXIES'),

        /*
        | Hosts aceitos no cabeçalho Host, em expressão regular: o host do
        | APP_URL, os IPs privados (10.x, 172.16 a 172.31 e 192.168.x), que o
        | health check do ALB usa ao chamar a instância pelo IP, e os nomes extras
        | de TRUSTED_HOSTS, separados por vírgula (ex.: o nome do container que o
        | teste de carga chama). Fora do local e dos testes, outro host recebe 400.
        | Os links dos e-mails não dependem do host aceito: fora do local, saem
        | sempre do APP_URL (AppServiceProvider::configureUrls).
        */
        'trusted_hosts' => array_values(array_filter([
            ($host = parse_url($appUrl, PHP_URL_HOST)) ? '^'.preg_quote($host).'$' : null,
            '^(10\.\d{1,3}|172\.(1[6-9]|2\d|3[01])|192\.168)\.\d{1,3}\.\d{1,3}$',
            ...array_map(
                fn (string $extra) => '^'.preg_quote($extra).'$',
                array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS')))),
            ),
        ])),

        /*
        | Content-Security-Policy (true ou false). Sem SECURITY_CSP, liga só em
        | production: no local o Vite de desenvolvimento serve os scripts de
        | outra porta.
        */
        'csp' => $isBlank(env('SECURITY_CSP'))
            ? env('APP_ENV', 'production') === 'production'
            : filter_var(env('SECURITY_CSP'), FILTER_VALIDATE_BOOL),
    ],

    /*
    | O cliente cancela o próprio agendamento até esta quantidade de horas
    | antes do início (usado pela agenda).
    */

    'client_cancel_min_hours' => $integer('CLIENT_CANCEL_MIN_HOURS', 2),

];
