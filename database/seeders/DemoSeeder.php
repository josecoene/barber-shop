<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\LoyaltyService;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\ChecksPasswordRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dados de demonstração, só para a homologação:
 *
 * - 3 barbeiros com login e 15 clientes, 4 deles com conta. Os logins usam a
 *   senha de DEMO_USER_PASSWORD e já nascem com o e-mail confirmado;
 * - os 4 serviços de exemplo do ServiceSeeder (ServiceSeeder::SAMPLES), só os
 *   que nunca existiram (o que o admin excluiu continua excluído);
 * - um mês de agenda (os 15 dias antes de hoje, hoje e os 14 dias seguintes,
 *   nos dias de expediente), com status variados;
 * - o pagamento de cada atendimento concluído, com formas variadas, alguns
 *   pendentes e um estornado;
 * - um cliente com prêmio de fidelidade disponível, com o atendimento concluído
 *   mais recente ainda sem pagamento, para mostrar o pagamento com o prêmio.
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * Na homologação (função homolog do docs/nuvem/DOCKER.md), com a senha só no
 * ambiente do comando, como o AdminUserSeeder no docs/nuvem/TERRAFORM.md, num
 * container descartável que não roda as migrations de novo:
 *
 *   homolog run --rm --no-deps -e DEMO_USER_PASSWORD -e RUN_MIGRATIONS=false app php artisan db:seed --class=DemoSeeder --force
 *
 * Regras:
 * - só roda com DEMO_SEED_ENABLED=true (config('barberflow.demo.enabled')) e
 *   com a DEMO_USER_PASSWORD definida, com a regra das senhas do sistema (no
 *   mínimo 8 caracteres). Sem uma das duas, avisa e não cria nada;
 * - sem Faker e sem sorteio: nomes e e-mails de listas fixas, no domínio
 *   demo.barberflow.test (o .test nunca entrega mensagem), e serviços, status e
 *   formas de pagamento em ciclos fixos;
 * - rodar de novo não duplica: cadastros e logins que já existem ficam como
 *   estão (o serviço fica com o preço, a duração e o status que o admin deu, e
 *   a senha de quem já tem login nunca muda), e a agenda só é criada se nenhum
 *   barbeiro e nenhum cliente da demonstração tiver agendamento. Ao contrário
 *   do ServiceSeeder, que devolve os 4 serviços ao padrão, este seeder não
 *   chama o ServiceSeeder;
 * - a agenda segue as regras do App\Services\AppointmentScheduler: dias,
 *   horário e fatias do expediente da config, término pela duração do serviço,
 *   preço copiado do serviço e nada depois do limite de dias para agendar.
 *   Ninguém fica em dois lugares ao mesmo tempo: cada cliente tem no máximo um
 *   atendimento por dia, e os de cada barbeiro ficam espaçados pelo serviço
 *   mais longo. O status segue a hora: concluído, faltou e cancelado no que já
 *   terminou, em atendimento no que está acontecendo, e agendado, confirmado e
 *   cancelado no que ainda vai começar. É gravada direto pelos models, porque a
 *   API não agenda no passado;
 * - o cliente com conta fica com no máximo CLIENT_MAX_ACTIVE_APPOINTMENTS - 1
 *   horários ativos, para poder agendar mais um pela conta dele, e os outros
 *   clientes ficam abaixo do prêmio de fidelidade.
 */
class DemoSeeder extends Seeder
{
    use ChecksPasswordRule;

    /**
     * Domínio dos e-mails de demonstração. O .test é reservado (RFC 2606): nenhum
     * e-mail chega a uma pessoa de verdade, e na homologação o Mailpit guarda tudo.
     */
    public const EMAIL_DOMAIN = 'demo.barberflow.test';

    /**
     * Dias de agenda antes de hoje e depois de hoje. Com hoje, são 30 dias.
     */
    private const PAST_DAYS = 15;

    private const FUTURE_DAYS = 14;

    /**
     * Atendimentos de cada barbeiro por dia, nos dias que já passaram, hoje e
     * nos dois dias seguintes. Depois a agenda vai ficando mais vazia
     * (perBarber()).
     */
    private const PAST_PER_BARBER = 3;

    /**
     * @var list<array{name: string, email: string, phone: string}>
     */
    private const BARBERS = [
        ['name' => 'Carlos Mendes', 'email' => 'carlos.mendes', 'phone' => '(11) 90000-0101'],
        ['name' => 'Rafael Souza', 'email' => 'rafael.souza', 'phone' => '(11) 90000-0102'],
        ['name' => 'Diego Almeida', 'email' => 'diego.almeida', 'phone' => '(11) 90000-0103'],
    ];

    /**
     * @var list<array{name: string, email: string, phone: string|null, account: bool}>
     */
    private const CLIENTS = [
        ['name' => 'Ana Paula Ribeiro', 'email' => 'ana.ribeiro', 'phone' => '(11) 90000-0201', 'account' => true],
        ['name' => 'Bruno Carvalho', 'email' => 'bruno.carvalho', 'phone' => '(11) 90000-0202', 'account' => false],
        ['name' => 'Camila Fernandes', 'email' => 'camila.fernandes', 'phone' => '(11) 90000-0203', 'account' => true],
        ['name' => 'Daniel Rocha', 'email' => 'daniel.rocha', 'phone' => '(11) 90000-0204', 'account' => false],
        ['name' => 'Eduarda Martins', 'email' => 'eduarda.martins', 'phone' => null, 'account' => false],
        ['name' => 'Felipe Gomes', 'email' => 'felipe.gomes', 'phone' => '(11) 90000-0206', 'account' => true],
        ['name' => 'Gabriela Lima', 'email' => 'gabriela.lima', 'phone' => '(11) 90000-0207', 'account' => false],
        ['name' => 'Henrique Barbosa', 'email' => 'henrique.barbosa', 'phone' => '(11) 90000-0208', 'account' => false],
        ['name' => 'Isabela Castro', 'email' => 'isabela.castro', 'phone' => null, 'account' => false],
        ['name' => 'João Pedro Nunes', 'email' => 'joao.nunes', 'phone' => '(11) 90000-0210', 'account' => true],
        ['name' => 'Larissa Teixeira', 'email' => 'larissa.teixeira', 'phone' => '(11) 90000-0211', 'account' => false],
        ['name' => 'Marcelo Pires', 'email' => 'marcelo.pires', 'phone' => '(11) 90000-0212', 'account' => false],
        ['name' => 'Natália Moreira', 'email' => 'natalia.moreira', 'phone' => null, 'account' => false],
        ['name' => 'Otávio Cardoso', 'email' => 'otavio.cardoso', 'phone' => '(11) 90000-0214', 'account' => false],
        ['name' => 'Patrícia Duarte', 'email' => 'patricia.duarte', 'phone' => '(11) 90000-0215', 'account' => false],
    ];

    /**
     * O cliente que fica com o prêmio de fidelidade disponível (ele tem conta e
     * vê o prêmio em Meus horários).
     */
    private const LOYAL_CLIENT = 'ana.ribeiro';

    /**
     * Serviços do ServiceSeeder, na ordem em que se repetem na agenda: o corte é
     * o mais pedido.
     */
    private const SERVICES = ['Corte de cabelo', 'Corte + Barba', 'Corte de cabelo', 'Barba', 'Corte de cabelo', 'Acabamento', 'Corte + Barba'];

    /**
     * Status do atendimento que já terminou, em ciclo: a cada 10, 8 concluídos,
     * 1 falta e 1 cancelado.
     */
    private const PAST_STATUSES = [
        AppointmentStatus::Completed, AppointmentStatus::Completed, AppointmentStatus::Completed, AppointmentStatus::NoShow,
        AppointmentStatus::Completed, AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::Completed,
        AppointmentStatus::Completed, AppointmentStatus::Completed,
    ];

    /**
     * Status do atendimento que ainda vai começar, em ciclo.
     */
    private const UPCOMING_STATUSES = [
        AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, AppointmentStatus::Scheduled, AppointmentStatus::Scheduled,
        AppointmentStatus::Confirmed, AppointmentStatus::Scheduled, AppointmentStatus::Cancelled, AppointmentStatus::Scheduled,
    ];

    /**
     * Formas de pagamento, em ciclo.
     */
    private const METHODS = [
        PaymentMethod::Pix, PaymentMethod::Cash, PaymentMethod::CreditCard, PaymentMethod::Pix,
        PaymentMethod::DebitCard, PaymentMethod::Cash, PaymentMethod::CreditCard, PaymentMethod::Pix,
    ];

    /**
     * A cada PENDING_EVERY pagamentos, o último fica pendente (9, e não 8 como o
     * ciclo das formas, para os pendentes não caírem todos na mesma forma). O
     * pagamento de número REFUNDED_PAYMENT (contando do zero) é o único estornado.
     */
    private const PENDING_EVERY = 9;

    private const REFUNDED_PAYMENT = 2;

    /**
     * Observações de alguns agendamentos (um a cada NOTES_EVERY).
     */
    private const NOTES = [
        'Prefere a máquina 2 nas laterais.',
        'Primeira vez na barbearia.',
        'Pediu para avisar se o horário adiantar.',
    ];

    private const NOTES_EVERY = 7;

    /**
     * Posição de cada ciclo na agenda que está sendo criada.
     *
     * @var array{service: int, past: int, upcoming: int, payment: int, appointment: int}
     */
    private array $cycles = ['service' => 0, 'past' => 0, 'upcoming' => 0, 'payment' => 0, 'appointment' => 0];

    /**
     * Visitas (atendimentos concluídos) e horários ativos de hoje em diante de
     * cada cliente, pelo id, na agenda que está sendo criada.
     *
     * @var array{completed: array<int, int>, active: array<int, int>}
     */
    private array $counts = ['completed' => [], 'active' => []];

    public function run(): void
    {
        if (! config('barberflow.demo.enabled')) {
            $this->command?->error('Seed de demonstração não executado: ele só roda na homologação, com DEMO_SEED_ENABLED=true. Nada foi criado.');

            return;
        }

        $password = config('barberflow.demo.password');

        if (($error = $this->passwordError($password)) !== null) {
            $this->command?->error("Seed de demonstração não executado. {$error} Nada foi criado.");

            return;
        }

        [$barbers, $clients, $agenda] = DB::transaction(function () use ($password) {
            $this->services();
            $barbers = $this->barbers($password);
            $clients = $this->clients($password);

            $hasAgenda = Appointment::query()
                ->whereIn('barber_id', array_map(fn (Barber $barber) => $barber->id, $barbers))
                ->orWhereIn('client_id', array_map(fn (Client $client) => $client->id, $clients))
                ->exists();

            return [$barbers, $clients, $hasAgenda ? null : $this->createAgenda($barbers, $clients)];
        });

        if ($agenda === null) {
            $this->command?->info('A agenda de demonstração já existe: nenhum agendamento novo foi criado.');
        } else {
            $this->command?->info("Agenda de demonstração criada: {$agenda['appointments']} agendamentos e {$agenda['payments']} pagamentos.");
        }

        $this->showLogins($barbers, $clients);
        $this->showLoyalty($clients, $agenda !== null);
    }

    /**
     * Mensagem da recusa da senha dos logins de demonstração, ou null quando ela
     * serve. A regra é a das senhas do sistema (Password::defaults()).
     */
    private function passwordError(#[\SensitiveParameter] mixed $password): ?string
    {
        if (blank($password)) {
            return 'Defina a senha dos logins de demonstração em DEMO_USER_PASSWORD, só no ambiente do comando (nunca num arquivo do repositório).';
        }

        return $this->passwordRuleError((string) $password, 'DEMO_USER_PASSWORD');
    }

    /**
     * Os 4 serviços de exemplo, criados só quando faltam: firstOrCreate pelo
     * nome, contando também os excluídos. O que já existe fica como está, com o
     * preço, a duração e o status que o admin deu, e o que o admin excluiu
     * continua excluído (a agenda usa só os ativos).
     */
    private function services(): void
    {
        foreach (ServiceSeeder::SAMPLES as $service) {
            Service::withTrashed()->firstOrCreate(['name' => $service['name']], $service);
        }
    }

    /**
     * Os barbeiros da demonstração, cada um com login. Quem já existe fica como
     * está; quem não tem login ganha um (Barber::syncLogin()), com o nome e o
     * e-mail do cadastro.
     *
     * @return list<Barber>
     */
    private function barbers(string $password): array
    {
        return array_map(function (array $data) use ($password): Barber {
            $email = $data['email'].'@'.self::EMAIL_DOMAIN;
            $barber = Barber::firstOrCreate(['email' => $email], ['name' => $data['name'], 'phone' => $data['phone']]);

            if ($barber->user_id === null && $this->emailIsFree($email, $barber->name)) {
                $barber->syncLogin($password);
            }

            return $barber;
        }, self::BARBERS);
    }

    /**
     * Os clientes da demonstração. Os marcados com conta ganham um login de
     * cliente, com o e-mail confirmado e ligado ao cadastro, como depois da
     * confirmação de e-mail (App\Listeners\LinkClientToVerifiedUser).
     *
     * @return list<Client>
     */
    private function clients(string $password): array
    {
        return array_map(function (array $data) use ($password): Client {
            $email = $data['email'].'@'.self::EMAIL_DOMAIN;
            $client = Client::firstOrCreate(['email' => $email], ['name' => $data['name'], 'phone' => $data['phone']]);

            if ($data['account'] && $client->user_id === null && $this->emailIsFree($email, $client->name)) {
                $user = new User(['name' => $client->name, 'email' => $email, 'password' => $password]);

                // O perfil e a confirmação do e-mail vão por forceFill: não estão no Fillable do User.
                $user->forceFill(['role' => UserRole::Client, 'email_verified_at' => now()])->save();

                $client->user()->associate($user)->save();
            }

            return $client;
        }, self::CLIENTS);
    }

    /**
     * Um e-mail que já é de outro usuário não ganha login de demonstração: o
     * cadastro fica sem login, e a conta que já existia continua como está.
     */
    private function emailIsFree(string $email, string $name): bool
    {
        if (! User::query()->where('email', $email)->exists()) {
            return true;
        }

        $this->command?->warn("O e-mail {$email} já é de outro usuário: {$name} ficou sem login de demonstração.");

        return false;
    }

    /**
     * Cria a agenda e os pagamentos.
     *
     * @param  list<Barber>  $barbers
     * @param  list<Client>  $clients
     * @return array{appointments: int, payments: int}
     */
    private function createAgenda(array $barbers, array $clients): array
    {
        $hours = app(AppointmentScheduler::class)->businessHours();
        $loyalty = app(LoyaltyService::class);
        $now = now()->toImmutable();
        $today = $now->startOfDay();

        // Os serviços de exemplo que estão ativos, na ordem do ciclo, com o preço e a duração de agora. Com dois
        // ativos do mesmo nome, vale o mais antigo.
        $byName = Service::query()->whereIn('name', self::SERVICES)->where('status', 'active')->orderBy('id')->get()
            ->unique('name')->keyBy('name');
        $services = array_values(array_filter(array_map(fn (string $name) => $byName->get($name), self::SERVICES)));

        if ($services === []) {
            return ['appointments' => 0, 'payments' => 0];
        }

        $longest = max(array_map(fn (Service $service) => $service->duration, $services));

        // O cliente fiel tem as visitas que dão o prêmio, todas pagas, e mais uma, a mais recente, sem
        // pagamento: é ela que se paga com o prêmio (o atendimento que está sendo pago não conta como visita).
        // Uma visita por dia, nos últimos dias de expediente.
        $loyal = $this->loyalClient($clients);
        $loyalVisits = $loyalty->visitsPerReward() + 1;
        $pastDates = $this->pastDates($today, $hours['days'], $loyalVisits);
        $loyalDates = array_map(fn (CarbonImmutable $date) => $date->toDateString(), array_slice($pastDates, -$loyalVisits));
        $loyalCount = 0;

        // Os outros clientes ficam abaixo do prêmio, e o cliente com conta deixa espaço para agendar
        // mais um horário pela conta dele.
        $limits = [
            'completed' => $loyalty->visitsPerReward() - 1,
            'active' => max(1, (int) config('barberflow.client_max_active_appointments')) - 1,
        ];

        $total = ['appointments' => 0, 'payments' => 0];

        foreach ([...$pastDates, ...$this->upcomingDates($today, $hours['days'])] as $dayIndex => $date) {
            $usedToday = [];
            $cursor = ($dayIndex * 7) % count($clients);
            $loyalToday = in_array($date->toDateString(), $loyalDates, true);

            foreach ($barbers as $barberIndex => $barber) {
                foreach ($this->starts($hours, $longest, $barberIndex, $dayIndex, $this->perBarber($today, $date)) as $start) {
                    $service = $services[$this->cycles['service']++ % count($services)];
                    $startsAt = $date->setTime(intdiv($start, 60), $start % 60);
                    $endsAt = $startsAt->addMinutes($service->duration);
                    $finished = $endsAt->lessThanOrEqualTo($now);
                    $paid = true;

                    if ($finished && $loyalToday) {
                        // A visita do dia do cliente fiel, sempre concluída.
                        $loyalToday = false;
                        $client = $loyal;
                        $status = AppointmentStatus::Completed;
                        $paid = ++$loyalCount < $loyalVisits;
                    } else {
                        $status = match (true) {
                            $finished => self::PAST_STATUSES[$this->cycles['past']++ % count(self::PAST_STATUSES)],
                            $startsAt->lessThanOrEqualTo($now) => AppointmentStatus::InProgress,
                            default => self::UPCOMING_STATUSES[$this->cycles['upcoming']++ % count(self::UPCOMING_STATUSES)],
                        };

                        $client = $this->pickClient($clients, $cursor, fn (Client $candidate) => ! isset($usedToday[$candidate->id])
                            && ! ($finished && $candidate->is($loyal))
                            && $this->withinLimits($candidate, $status, $limits));
                    }

                    // Nenhum cliente livre para este horário: ele fica vago.
                    if ($client === null) {
                        continue;
                    }

                    $usedToday[$client->id] = true;
                    $appointment = $this->book($client, $barber, $service, $startsAt, $endsAt, $status);
                    $total['appointments']++;

                    if ($status === AppointmentStatus::Completed && $paid) {
                        $this->pay($appointment, $endsAt);
                        $total['payments']++;
                    }
                }
            }
        }

        return $total;
    }

    /**
     * Dias de expediente antes de hoje, do mais antigo para o mais recente: os
     * dos últimos PAST_DAYS dias e, se não bastarem para as visitas do cliente
     * fiel (uma por dia), os anteriores que faltarem, até um ano para trás.
     *
     * @param  list<int>  $businessDays
     * @return list<CarbonImmutable>
     */
    private function pastDates(CarbonImmutable $today, array $businessDays, int $minimum): array
    {
        $window = $today->subDays(self::PAST_DAYS);
        $dates = [];

        for ($date = $today->subDay(); $date->greaterThanOrEqualTo($today->subYear()); $date = $date->subDay()) {
            if ($date->lessThan($window) && count($dates) >= $minimum) {
                break;
            }

            if (in_array($date->dayOfWeekIso, $businessDays, true)) {
                $dates[] = $date;
            }
        }

        return array_reverse($dates);
    }

    /**
     * Hoje e os próximos FUTURE_DAYS dias de expediente, sem passar do limite de
     * dias para agendar (config('barberflow.booking_days_ahead')).
     *
     * @param  list<int>  $businessDays
     * @return list<CarbonImmutable>
     */
    private function upcomingDates(CarbonImmutable $today, array $businessDays): array
    {
        $last = min(self::FUTURE_DAYS, max(0, (int) config('barberflow.booking_days_ahead')));
        $dates = [];

        for ($offset = 0; $offset <= $last; $offset++) {
            $date = $today->addDays($offset);

            if (in_array($date->dayOfWeekIso, $businessDays, true)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Atendimentos de cada barbeiro no dia: PAST_PER_BARBER até depois de
     * amanhã e, depois, menos, como uma agenda que ainda vai se encher (2 até o
     * fim da semana e 1 na semana seguinte).
     */
    private function perBarber(CarbonImmutable $today, CarbonImmutable $date): int
    {
        $daysAhead = (int) $today->diffInDays($date);

        return match (true) {
            $daysAhead <= 2 => self::PAST_PER_BARBER,
            $daysAhead <= 6 => 2,
            default => 1,
        };
    }

    /**
     * Inícios dos atendimentos do barbeiro no dia, em minutos desde a
     * meia-noite. Saem de uma grade que começa na abertura (a do segundo
     * barbeiro, uma fatia depois) e anda o serviço mais longo, arredondado para
     * fatias: os atendimentos do mesmo barbeiro nunca se sobrepõem, todos
     * começam numa fatia e terminam dentro do expediente. Os $count inícios
     * ficam espalhados pela grade, numa posição que muda a cada dia.
     *
     * @param  array{opening: int, closing: int, slot: int, days: list<int>}  $hours
     * @return list<int>
     */
    private function starts(array $hours, int $longest, int $barberIndex, int $dayIndex, int $count): array
    {
        $step = max(1, (int) ceil($longest / $hours['slot'])) * $hours['slot'];
        $grid = [];

        for ($start = $hours['opening'] + ($barberIndex % 2) * $hours['slot']; $start + $longest <= $hours['closing']; $start += $step) {
            $grid[] = $start;
        }

        if ($count <= 0 || $grid === []) {
            return [];
        }

        if (count($grid) <= $count) {
            return $grid;
        }

        $stride = intdiv(count($grid), $count);
        $offset = ($dayIndex + $barberIndex) % $stride;

        return array_map(fn (int $index) => $grid[$offset + $index * $stride], range(0, $count - 1));
    }

    /**
     * O próximo cliente da lista, a partir de $cursor e dando a volta, que pode
     * ficar com o horário. O cursor passa para depois dele.
     *
     * @param  list<Client>  $clients
     * @param  callable(Client): bool  $canTake
     */
    private function pickClient(array $clients, int &$cursor, callable $canTake): ?Client
    {
        $total = count($clients);

        for ($step = 0; $step < $total; $step++) {
            $index = ($cursor + $step) % $total;

            if ($canTake($clients[$index])) {
                $cursor = $index + 1;

                return $clients[$index];
            }
        }

        return null;
    }

    /**
     * Os limites de cada cliente: as visitas abaixo do prêmio de fidelidade e,
     * para quem tem conta, os horários ativos abaixo do máximo de quem agenda
     * pela conta.
     *
     * @param  array{completed: int, active: int}  $limits
     */
    private function withinLimits(Client $client, AppointmentStatus $status, array $limits): bool
    {
        if ($status === AppointmentStatus::Completed && ($this->counts['completed'][$client->id] ?? 0) >= $limits['completed']) {
            return false;
        }

        return ! (in_array($status, AppointmentStatus::active(), true)
            && $client->user_id !== null
            && ($this->counts['active'][$client->id] ?? 0) >= $limits['active']);
    }

    /**
     * Grava o agendamento, com o preço copiado do serviço (como o
     * AppointmentScheduler faz), e conta a visita ou o horário ativo do cliente.
     */
    private function book(Client $client, Barber $barber, Service $service, CarbonImmutable $startsAt, CarbonImmutable $endsAt, AppointmentStatus $status): Appointment
    {
        $index = $this->cycles['appointment']++;

        if ($status === AppointmentStatus::Completed) {
            $this->counts['completed'][$client->id] = ($this->counts['completed'][$client->id] ?? 0) + 1;
        }

        if (in_array($status, AppointmentStatus::active(), true)) {
            $this->counts['active'][$client->id] = ($this->counts['active'][$client->id] ?? 0) + 1;
        }

        return Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'date' => $startsAt->toDateString(),
            'start_time' => $startsAt->format('H:i:s'),
            'end_time' => $endsAt->format('H:i:s'),
            'status' => $status,
            'price' => $service->price,
            'notes' => $index % self::NOTES_EVERY === 3 ? self::NOTES[intdiv($index, self::NOTES_EVERY) % count(self::NOTES)] : null,
        ]);
    }

    /**
     * Pagamento do atendimento concluído, no valor copiado no agendamento. O
     * pago, e o estornado, que já foi pago, ficam com o paid_at na hora em que o
     * atendimento terminou; o pendente fica sem data.
     */
    private function pay(Appointment $appointment, CarbonImmutable $endsAt): void
    {
        $index = $this->cycles['payment']++;

        $status = match (true) {
            $index === self::REFUNDED_PAYMENT => PaymentStatus::Refunded,
            $index % self::PENDING_EVERY === self::PENDING_EVERY - 1 => PaymentStatus::Pending,
            default => PaymentStatus::Paid,
        };

        Payment::create([
            'appointment_id' => $appointment->id,
            'amount' => $appointment->price,
            'method' => self::METHODS[$index % count(self::METHODS)],
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Pending ? null : $endsAt,
        ]);
    }

    /**
     * Mostra os logins de demonstração, sem a senha.
     *
     * @param  list<Barber>  $barbers
     * @param  list<Client>  $clients
     */
    private function showLogins(array $barbers, array $clients): void
    {
        $rows = [];

        foreach ($barbers as $barber) {
            if ($barber->user_id !== null) {
                $rows[] = ['Barbeiro', $barber->name, $barber->email];
            }
        }

        foreach ($clients as $client) {
            if ($client->user_id !== null) {
                $rows[] = ['Cliente', $client->name, $client->email];
            }
        }

        $this->command?->table(['Perfil', 'Nome', 'E-mail do login'], $rows);
        $this->command?->info('A senha é a da DEMO_USER_PASSWORD com que cada login foi criado: rodar de novo não troca a senha de ninguém.');
    }

    /**
     * Diz se o cliente fiel está com o prêmio de fidelidade disponível. Logo
     * depois de criar a agenda, lembra que a visita mais recente dele ficou sem
     * pagamento, para registrar com o prêmio.
     *
     * @param  list<Client>  $clients
     */
    private function showLoyalty(array $clients, bool $agendaCreated): void
    {
        $loyal = $this->loyalClient($clients);

        if (app(LoyaltyService::class)->summary($loyal)['available_rewards'] < 1) {
            $this->command?->warn("{$loyal->name} está sem prêmio de fidelidade disponível agora.");

            return;
        }

        $this->command?->info($agendaCreated
            ? "Cliente com prêmio de fidelidade disponível: {$loyal->name}. O atendimento concluído mais recente desse cliente ficou sem pagamento, para registrar com o prêmio."
            : "Cliente com prêmio de fidelidade disponível: {$loyal->name}.");
    }

    /**
     * O cliente que fica com o prêmio de fidelidade (LOYAL_CLIENT).
     *
     * @param  list<Client>  $clients
     */
    private function loyalClient(array $clients): Client
    {
        return collect($clients)->firstOrFail(fn (Client $client) => $client->email === self::LOYAL_CLIENT.'@'.self::EMAIL_DOMAIN);
    }
}
