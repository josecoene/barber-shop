<?php

namespace Tests\Feature;

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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Seed de demonstração (Database\Seeders\DemoSeeder). O "agora" fica fixo na
 * quarta-feira 18/11/2026 às 14:10, no meio do expediente, e a flag e a senha
 * dos logins de demonstração vão pela config, como viriam do DEMO_SEED_ENABLED
 * e do DEMO_USER_PASSWORD. Os testes das regras da agenda rodam com o
 * expediente padrão e com um expediente de três dias por semana, mais curto e
 * com fatias de 20 minutos, em que o cliente fiel precisa de dias de antes da
 * janela de 15 dias para chegar ao prêmio.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-18 14:10:00';

    private const DEMO_PASSWORD = 'senha-da-demonstracao-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        config(['barberflow.demo' => ['enabled' => true, 'password' => self::DEMO_PASSWORD]]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function businessHours(): array
    {
        return [
            'expediente padrão' => [[]],
            'três dias por semana, das 10h às 18h, de 20 em 20 minutos' => [[
                'barberflow.business' => ['opening' => '10:00', 'closing' => '18:00', 'slot_minutes' => 20, 'days' => [2, 4, 6]],
            ]],
        ];
    }

    public function test_does_not_run_without_the_flag(): void
    {
        config(['barberflow.demo.enabled' => false]);

        $this->seedDemo()
            ->expectsOutputToContain('DEMO_SEED_ENABLED=true')
            ->assertSuccessful();

        $this->assertNothingWasCreated();
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function invalidPasswords(): array
    {
        return [
            'sem senha' => [null, 'Defina a senha dos logins de demonstração em DEMO_USER_PASSWORD'],
            'vazia' => ['', 'Defina a senha dos logins de demonstração em DEMO_USER_PASSWORD'],
            'só espaços' => ['          ', 'Defina a senha dos logins de demonstração em DEMO_USER_PASSWORD'],
            'mais curta que a das contas' => ['curta12', 'O campo DEMO_USER_PASSWORD deve ter pelo menos 8 caracteres.'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_does_not_run_without_a_valid_password(?string $password, string $message): void
    {
        config(['barberflow.demo.password' => $password]);

        $this->seedDemo()
            ->expectsOutputToContain($message)
            ->assertSuccessful();

        $this->assertNothingWasCreated();
    }

    public function test_creates_the_logins_the_clients_and_a_month_of_agenda(): void
    {
        $this->seedDemo()
            ->expectsOutputToContain('Agenda de demonstração criada')
            ->doesntExpectOutputToContain(self::DEMO_PASSWORD)
            ->assertSuccessful();

        $this->assertSame(4, Service::count());

        // 3 barbeiros, todos com login de barbeiro, com o nome e o e-mail do cadastro.
        $barbers = Barber::with('user')->get();
        $this->assertCount(3, $barbers);

        foreach ($barbers as $barber) {
            $this->assertSame(UserRole::Barber, $barber->user->role);
            $this->assertSame([$barber->name, $barber->email], [$barber->user->name, $barber->user->email]);
            $this->assertDemoLogin($barber->user);
        }

        // 15 clientes, 4 com conta de cliente ligada ao cadastro.
        $clients = Client::with('user')->get();
        $this->assertCount(15, $clients);
        $this->assertCount(4, $clients->whereNotNull('user_id'));

        foreach ($clients->whereNotNull('user_id') as $client) {
            $this->assertSame(UserRole::Client, $client->user->role);
            $this->assertSame($client->email, $client->user->email);
            $this->assertDemoLogin($client->user);
        }

        // Só os logins de demonstração: o seeder não cria admin nem mexe em outros usuários.
        $this->assertSame(7, User::count());

        foreach ([...$barbers, ...$clients] as $person) {
            $this->assertStringEndsWith('@'.DemoSeeder::EMAIL_DOMAIN, $person->email);
        }

        // Um mês de agenda: dos 15 dias antes de hoje aos 14 dias seguintes, com os status variados.
        $dates = Appointment::query()->pluck('date')->map(fn (Carbon $date) => $date->toDateString());
        $this->assertGreaterThanOrEqual('2026-11-03', $dates->min());
        $this->assertLessThanOrEqual('2026-11-05', $dates->min());
        $this->assertLessThanOrEqual('2026-12-02', $dates->max());
        $this->assertGreaterThanOrEqual('2026-11-28', $dates->max());
        $this->assertTrue($dates->contains('2026-11-18'));

        $statuses = Appointment::query()->pluck('status')->unique();

        foreach ([AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $status) {
            $this->assertContains($status, $statuses);
        }
    }

    public function test_running_again_does_not_duplicate_or_change_anything(): void
    {
        $this->seedDemo()->assertSuccessful();

        // No ensaio, o admin muda o preço e a duração de um serviço de exemplo, inativa outro e exclui um terceiro.
        Service::where('name', 'Corte de cabelo')->update(['price' => 45.00, 'duration' => 35]);
        Service::where('name', 'Acabamento')->update(['status' => 'inactive']);
        Service::where('name', 'Barba')->firstOrFail()->delete();

        $counts = $this->counts();
        $services = $this->services();

        $this->seedDemo()
            ->expectsOutputToContain('A agenda de demonstração já existe')
            ->assertSuccessful();

        // Nem dias depois, com outra senha: a agenda não nasce de novo, e a senha de quem já tem login não muda.
        $this->travelTo(Carbon::parse('2026-11-21 10:00:00'));
        config(['barberflow.demo.password' => 'outra-senha-da-demonstracao']);

        $this->seedDemo()->assertSuccessful();

        $this->assertSame($counts, $this->counts());

        // Os serviços ficam como o admin deixou: o preço, a duração e o status dele, e o excluído continua excluído.
        $this->assertSame($services, $this->services());
        $this->assertSame(['45.00', 35, 'active', false], $services['Corte de cabelo']);
        $this->assertSame('inactive', $services['Acabamento'][2]);
        $this->assertTrue($services['Barba'][3]);

        foreach (User::all() as $user) {
            $this->assertTrue(Hash::check(self::DEMO_PASSWORD, $user->password));
        }
    }

    #[DataProvider('businessHours')]
    public function test_no_one_is_in_two_places_at_the_same_time(array $config): void
    {
        config($config);

        $this->seedDemo()->assertSuccessful();

        // Cancelado e faltou não ocupam o horário, como no AppointmentScheduler.
        $occupying = Appointment::query()->whereIn('status', AppointmentStatus::occupying())->get();
        $this->assertNotEmpty($occupying);

        foreach (['barber_id', 'client_id'] as $owner) {
            foreach ($occupying->groupBy(fn (Appointment $appointment) => $appointment->{$owner}.' '.$appointment->date->toDateString()) as $day) {
                $sorted = $day->sortBy('start_time')->values();

                for ($i = 1; $i < $sorted->count(); $i++) {
                    $this->assertTrue(
                        $sorted[$i - 1]->end_time <= $sorted[$i]->start_time,
                        "Sobreposição em {$owner}: agendamentos {$sorted[$i - 1]->id} e {$sorted[$i]->id}.",
                    );
                }
            }
        }
    }

    #[DataProvider('businessHours')]
    public function test_the_agenda_follows_the_rules_of_the_scheduler(array $config): void
    {
        config($config);

        $this->seedDemo()->assertSuccessful();

        $hours = app(AppointmentScheduler::class)->businessHours();
        $lastDate = today()->addDays(config('barberflow.booking_days_ahead'))->toDateString();
        $appointments = Appointment::with('service')->get();
        $this->assertNotEmpty($appointments);

        foreach ($appointments as $appointment) {
            $start = $this->minutes($appointment->start_time);
            $end = $this->minutes($appointment->end_time);

            // Dia, horário e fatia do expediente, término pela duração do serviço e preço copiado.
            $this->assertContains($appointment->date->dayOfWeekIso, $hours['days']);
            $this->assertGreaterThanOrEqual($hours['opening'], $start);
            $this->assertLessThanOrEqual($hours['closing'], $end);
            $this->assertSame(0, ($start - $hours['opening']) % $hours['slot']);
            $this->assertStringEndsWith(':00', $appointment->start_time);
            $this->assertSame($start + $appointment->service->duration, $end);
            $this->assertSame($appointment->service->price, $appointment->price);
            $this->assertSame('active', $appointment->service->status);
            $this->assertLessThanOrEqual($lastDate, $appointment->date->toDateString());

            // O status segue a hora: em atendimento, concluído e faltou só depois do início.
            $startsAt = $appointment->startsAt();
            $endsAt = $startsAt->addMinutes($appointment->service->duration);

            $expected = match (true) {
                $endsAt->lessThanOrEqualTo(now()) => [AppointmentStatus::Completed, AppointmentStatus::NoShow, AppointmentStatus::Cancelled],
                $startsAt->lessThanOrEqualTo(now()) => [AppointmentStatus::InProgress],
                default => [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed, AppointmentStatus::Cancelled],
            };

            $this->assertContains($appointment->status, $expected, "Agendamento {$appointment->id} com status fora da hora.");
        }

        // O cliente com conta pode agendar mais um horário pela conta dele.
        foreach (Client::query()->whereNotNull('user_id')->get() as $client) {
            $this->assertLessThan(config('barberflow.client_max_active_appointments'), $client->appointments()->upcoming()->count());
        }
    }

    public function test_only_completed_appointments_are_paid(): void
    {
        $this->seedDemo()->assertSuccessful();

        $payments = Payment::with('appointment')->get();
        $this->assertNotEmpty($payments);

        foreach ($payments as $payment) {
            $this->assertSame(AppointmentStatus::Completed, $payment->appointment->status);
            $this->assertSame($payment->appointment->price, $payment->amount);
            $this->assertSame('0.00', $payment->discount);
            $this->assertFalse($payment->loyalty_reward);

            // Pago e estornado com a data do pagamento; pendente, ainda sem ela.
            $this->assertSame($payment->status === PaymentStatus::Pending, $payment->paid_at === null);
        }

        // Formas variadas, alguns pendentes e um estornado.
        $this->assertEqualsCanonicalizing(
            array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases()),
            $payments->map(fn (Payment $payment) => $payment->method->value)->unique()->values()->all(),
        );
        $this->assertGreaterThan(1, $payments->where('status', PaymentStatus::Pending)->count());
        $this->assertCount(1, $payments->where('status', PaymentStatus::Refunded));
        $this->assertGreaterThan($payments->count() / 2, $payments->where('status', PaymentStatus::Paid)->count());

        // Todo concluído tem pagamento, menos o que fica para o pagamento com o prêmio de fidelidade.
        $this->assertSame(1, Appointment::query()->awaitingPayment()->count());
    }

    #[DataProvider('businessHours')]
    public function test_one_client_has_a_loyalty_reward_to_use(array $config): void
    {
        config($config);

        $this->seedDemo()
            ->expectsOutputToContain('Cliente com prêmio de fidelidade disponível: Ana Paula Ribeiro')
            ->assertSuccessful();

        $loyalty = app(LoyaltyService::class);
        $withReward = Client::all()->filter(fn (Client $client) => $loyalty->summary($client)['available_rewards'] > 0);

        // Só um, e com conta: ele vê o prêmio em Meus horários.
        $this->assertCount(1, $withReward);
        $client = $withReward->first();
        $this->assertNotNull($client->user_id);
        $this->assertSame(1, $loyalty->summary($client)['available_rewards']);

        // O atendimento concluído dele que está sem pagamento sai de graça com o prêmio (desconto padrão de 100%).
        $appointment = $client->appointments()->awaitingPayment()->sole();
        $this->actingAsAdmin();

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix', 'use_loyalty_reward' => true])
            ->assertCreated()
            ->assertJsonPath('loyalty_reward', true)
            ->assertJsonPath('discount', $appointment->price)
            ->assertJsonPath('amount', '0.00');
    }

    private function seedDemo(): PendingCommand
    {
        return $this->artisan('db:seed', ['--class' => DemoSeeder::class]);
    }

    /**
     * Login de demonstração: e-mail confirmado, a senha de DEMO_USER_PASSWORD e
     * a entrada liberada.
     */
    private function assertDemoLogin(User $user): void
    {
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::DEMO_PASSWORD, $user->password));
        $this->assertTrue($user->canLogIn());
    }

    private function assertNothingWasCreated(): void
    {
        $this->assertSame(
            ['users' => 0, 'barbers' => 0, 'clients' => 0, 'services' => 0, 'appointments' => 0, 'payments' => 0],
            $this->counts(),
        );
    }

    /**
     * @return array{users: int, barbers: int, clients: int, services: int, appointments: int, payments: int}
     */
    private function counts(): array
    {
        return [
            'users' => User::count(),
            'barbers' => Barber::withTrashed()->count(),
            'clients' => Client::withTrashed()->count(),
            'services' => Service::withTrashed()->count(),
            'appointments' => Appointment::count(),
            'payments' => Payment::count(),
        ];
    }

    /**
     * Preço, duração, status e se foi excluído, de cada serviço, pelo nome.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: bool}>
     */
    private function services(): array
    {
        return Service::withTrashed()->orderBy('id')->get()
            ->mapWithKeys(fn (Service $service) => [$service->name => [$service->price, $service->duration, $service->status, $service->trashed()]])
            ->all();
    }

    /**
     * "09:30:00" em minutos desde a meia-noite (570).
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
