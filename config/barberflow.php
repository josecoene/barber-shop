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

$text = static fn (string $key, string $default): string => $isBlank(env($key)) ? $default : trim((string) env($key));

// Lista de números separados por vírgula (ex.: "1,2,3"). O que não é número fica de fora.
$integers = static fn (string $key, string $default): array => array_values(array_unique(array_map(
    'intval',
    array_filter(array_map('trim', explode(',', $text($key, $default))), 'is_numeric'),
)));

$appUrl = $isBlank(env('APP_URL')) ? 'http://localhost' : env('APP_URL');

return [

    /*
    | Administrador inicial, criado pelo AdminUserSeeder. Sem e-mail, sem senha
    | ou com uma senha de menos de 8 caracteres (a regra das outras senhas do
    | sistema), o seeder só avisa e não cria nada.
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

        // "Enviar lembrete agora", por usuário: cada envio manda um e-mail ao cliente. O limite por
        // agendamento (um envio a cada reminders.resend_minutes minutos) fica no ReminderSender.
        'reminders' => 10,
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

    /*
    | Quantos agendamentos ativos (agendado, confirmado ou em atendimento) de
    | hoje em diante o cliente pode ter ao agendar pela conta dele. No limite,
    | ele cancela um para marcar outro. A barbearia (admin e barbeiro) agenda
    | para o cliente sem esse limite. O mínimo é 1.
    */

    'client_max_active_appointments' => $integer('CLIENT_MAX_ACTIVE_APPOINTMENTS', 3),

    /*
    | Até quantos dias depois de hoje dá para agendar ou remarcar, em qualquer
    | perfil. Com 60, hoje mais 60 dias é a última data aceita.
    */

    'booking_days_ahead' => $integer('BOOKING_DAYS_AHEAD', 60),

    /*
    | Expediente da agenda (App\Services\AppointmentScheduler):
    | - opening e closing: abertura e fechamento, em HH:MM. O atendimento
    |   começa e termina dentro desse intervalo;
    | - slot_minutes: tamanho da fatia. Os horários começam de fatia em fatia,
    |   a partir da abertura (09:00, 09:30, 10:00...);
    | - days: dias de atendimento no padrão ISO, de 1 (segunda) a 7 (domingo),
    |   separados por vírgula no .env.
    */

    'business' => [
        'opening' => $text('BUSINESS_OPENING', '09:00'),
        'closing' => $text('BUSINESS_CLOSING', '19:00'),
        'slot_minutes' => $integer('BUSINESS_SLOT_MINUTES', 30),
        'days' => $integers('BUSINESS_DAYS', '1,2,3,4,5,6'),
    ],

    /*
    | Programa de fidelidade (App\Services\LoyaltyService):
    | - visits: a cada quantas visitas (atendimentos concluídos) o cliente
    |   ganha um prêmio. O mínimo é 1;
    | - discount_percent: desconto do prêmio no pagamento de um atendimento,
    |   de 0 a 100 (100 é o atendimento grátis).
    */

    'loyalty' => [
        'visits' => $integer('LOYALTY_VISITS', 10),
        'discount_percent' => $integer('LOYALTY_DISCOUNT_PERCENT', 100),
    ],

    /*
    | Lembrete por e-mail (App\Services\ReminderSender):
    | - hours_before: o comando appointments:send-reminders, que roda a cada 15
    |   minutos, manda o lembrete dos agendamentos que começam nas próximas
    |   horas. O mínimo é 1;
    | - resend_minutes: o "Enviar lembrete agora" da agenda espera esse tempo
    |   desde o último lembrete do mesmo agendamento (do comando ou manual),
    |   mesmo que ele tenha sido remarcado. Antes disso, a resposta é 429 com
    |   Retry-After. Valor fixo, sem variável de ambiente. O limite por usuário
    |   fica em rate_limits.reminders.
    */

    'reminders' => [
        'hours_before' => $integer('REMINDER_HOURS_BEFORE', 24),
        'resend_minutes' => 10,
    ],

    /*
    | Dados da barbearia que o lembrete por e-mail mostra. Sem nome, vale o
    | APP_NAME; endereço e telefone vazios não aparecem no e-mail.
    */

    'barbershop' => [
        'name' => $text('BARBERSHOP_NAME', $text('APP_NAME', 'BarberFlow')),
        'address' => $text('BARBERSHOP_ADDRESS', ''),
        'phone' => $text('BARBERSHOP_PHONE', ''),
    ],

    /*
    | Seed de demonstração (Database\Seeders\DemoSeeder), só para a
    | homologação: barbeiros com login, clientes, um mês de agenda com
    | pagamentos e um cliente com prêmio de fidelidade.
    | - enabled: o seeder só roda com DEMO_SEED_ENABLED=true (a homologação
    |   liga no docker-compose.homolog.yml). Desligado, ele não cria nada;
    | - password: a senha dos logins de demonstração (barbeiros e clientes com
    |   conta), de DEMO_USER_PASSWORD. Fica só no ambiente do comando, nunca
    |   num arquivo do repositório. Sem ela, o seeder também não cria nada.
    */

    'demo' => [
        'enabled' => filter_var(env('DEMO_SEED_ENABLED', false), FILTER_VALIDATE_BOOL),
        'password' => $isBlank(env('DEMO_USER_PASSWORD')) ? null : (string) env('DEMO_USER_PASSWORD'),
    ],

];
