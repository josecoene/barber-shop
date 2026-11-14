<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quem pode o quê nos pagamentos: o barbeiro registra e vê os pagamentos da
 * agenda dele e marca como pago um pendente, sem corrigir nem estornar; o
 * cliente só vê os próprios. O 401 sem login de todas as rotas novas fica no
 * AuthorizationTest, e o dashboard no DashboardApiTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 ao meio-dia.
 */
class PaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    private const UNVERIFIED = ['message' => 'Confirme seu e-mail para continuar.'];

    private Barber $barber;

    private Barber $otherBarber;

    private Client $client;

    private Client $otherClient;

    private Service $service;

    /**
     * Pedro atendeu Ana às 10:00; o outro barbeiro atendeu Bruno às 11:00.
     */
    private Appointment $own;

    private Appointment $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));

        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->otherBarber = Barber::create(['name' => 'Joao', 'email' => 'joao@barberflow.test']);
        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->otherClient = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);

        $this->own = $this->appointment($this->barber, $this->client, '10:00:00');
        $this->other = $this->appointment($this->otherBarber, $this->otherClient, '11:00:00');
    }

    /**
     * Atendimento concluído na segunda, com 30 minutos, gravado direto no banco.
     */
    private function appointment(Barber $barber, Client $client, string $start, string $status = 'completed'): Appointment
    {
        return Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => $start,
            'end_time' => Carbon::parse(self::MONDAY.' '.$start)->addMinutes(30)->format('H:i:s'),
            'status' => $status,
            'price' => 35,
        ]);
    }

    private function payment(Appointment $appointment, string $status = 'paid'): Payment
    {
        return Payment::create([
            'appointment_id' => $appointment->id,
            'amount' => 35,
            'method' => 'pix',
            'status' => $status,
            'paid_at' => $status === 'pending' ? null : now(),
        ]);
    }

    public function test_barber_records_payments_only_in_the_own_agenda(): void
    {
        $this->actingAsBarber($this->barber);

        $this->postJson('/api/payments', ['appointment_id' => $this->own->id, 'method' => 'cash', 'status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('appointment.barber', ['id' => $this->barber->id, 'name' => 'Pedro']);

        $this->postJson('/api/payments', ['appointment_id' => $this->other->id, 'method' => 'cash'])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame(1, Payment::count());
        $this->assertNull($this->other->payment()->first());
    }

    public function test_barber_sees_only_the_payments_of_the_own_agenda(): void
    {
        $own = $this->payment($this->own);
        $other = $this->payment($this->other);
        $this->actingAsBarber($this->barber);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('totals.paid', ['payments' => 1, 'total' => '35.00']);

        // O filtro não abre os pagamentos de outro barbeiro.
        $this->getJson("/api/payments?barber_id={$this->otherBarber->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/payments/{$own->id}")->assertOk();
        $this->getJson("/api/payments/{$other->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$other->id}/status", ['status' => 'paid'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_barber_marks_a_pending_payment_of_the_own_agenda_as_paid(): void
    {
        $own = $this->payment($this->own, 'pending');
        $other = $this->payment($this->other, 'pending');
        $this->actingAsBarber($this->barber);

        $this->patchJson("/api/payments/{$own->id}/status", ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_at', now()->toJSON());

        $this->patchJson("/api/payments/{$other->id}/status", ['status' => 'paid'])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame('pending', $other->fresh()->status->value);
    }

    public function test_barber_does_not_correct_or_refund(): void
    {
        $own = $this->payment($this->own);
        $this->actingAsBarber($this->barber);

        $this->putJson("/api/payments/{$own->id}", ['amount' => 1])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$own->id}", ['method' => 'cash', 'notes' => 'Corrigido'])->assertForbidden();
        $this->patchJson("/api/payments/{$own->id}/status", ['status' => 'refunded'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$own->id}/status", ['status' => 'pending'])->assertForbidden();

        $fresh = $own->fresh();
        $this->assertSame('35.00', $fresh->amount);
        $this->assertSame('pix', $fresh->method->value);
        $this->assertNull($fresh->notes);
        $this->assertSame('paid', $fresh->status->value);
    }

    public function test_client_sees_only_the_own_payments(): void
    {
        $own = $this->payment($this->own);
        $other = $this->payment($this->other);
        $this->actingAsClient($this->client);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.appointment.barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('totals.paid', ['payments' => 1, 'total' => '35.00']);

        // O filtro não abre os pagamentos de outro cliente.
        $this->getJson("/api/payments?client_id={$this->otherClient->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/payments/{$own->id}")->assertOk()->assertJsonPath('status_label', 'Pago');
        $this->getJson("/api/payments/{$other->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_client_does_not_record_correct_or_change_payments(): void
    {
        $pending = $this->payment($this->own, 'pending');
        $this->actingAsClient($this->client);

        $second = $this->appointment($this->barber, $this->client, '14:00:00');

        $this->postJson('/api/payments', ['appointment_id' => $second->id, 'method' => 'pix'])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);
        $this->putJson("/api/payments/{$pending->id}", ['amount' => 1])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$pending->id}/status", ['status' => 'paid'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$pending->id}/status", ['status' => 'refunded'])->assertForbidden();

        $this->assertSame(1, Payment::count());
        $this->assertSame('pending', $pending->fresh()->status->value);
        $this->assertSame('35.00', $pending->fresh()->amount);
    }

    public function test_client_sees_in_the_own_appointments_whether_they_are_paid(): void
    {
        $this->payment($this->own);
        $this->payment($this->other);
        $this->actingAsClient($this->client);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment.status', 'paid')
            ->assertJsonPath('data.0.payment.status_label', 'Pago')
            ->assertJsonMissingPath('data.0.payment.amount')
            ->assertJsonMissingPath('data.0.payment.method');
    }

    public function test_without_payment_respects_the_agenda_of_each_profile(): void
    {
        $this->actingAsBarber($this->barber);

        $this->getJson('/api/appointments?without_payment=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->own->id);

        $this->getJson("/api/appointments?without_payment=1&barber_id={$this->otherBarber->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsClient($this->otherClient);

        $this->getJson('/api/appointments?without_payment=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->other->id);
    }

    public function test_client_without_the_confirmed_email_is_refused(): void
    {
        $payment = $this->payment($this->own);
        $this->actingAsClient($this->client, verified: false);

        $this->getJson('/api/payments')->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->postJson('/api/payments', ['appointment_id' => $this->own->id, 'method' => 'pix'])->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->getJson("/api/payments/{$payment->id}")->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->putJson("/api/payments/{$payment->id}", ['amount' => 1])->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->patchJson("/api/payments/{$payment->id}/status", ['status' => 'refunded'])->assertForbidden()->assertExactJson(self::UNVERIFIED);

        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_accounts_without_a_record_are_refused(): void
    {
        $payment = $this->payment($this->own);

        // Conta de cliente confirmada e login de barbeiro, os dois sem cadastro ligado.
        foreach ([UserRole::Client, UserRole::Barber] as $role) {
            $user = User::create(['name' => 'Sem Cadastro', 'email' => "sem.cadastro.{$role->value}@barberflow.test", 'password' => self::PASSWORD]);
            $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
            Sanctum::actingAs($user);

            $this->getJson('/api/payments')->assertForbidden()->assertExactJson(self::FORBIDDEN);
            $this->getJson("/api/payments/{$payment->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
            $this->postJson('/api/payments', ['appointment_id' => $this->other->id, 'method' => 'pix'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        }

        $this->assertSame(1, Payment::count());
    }

    public function test_user_without_role_is_refused_everywhere(): void
    {
        $payment = $this->payment($this->own, 'pending');
        $user = User::create(['name' => 'Sem Perfil', 'email' => 'sem.perfil@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/payments')->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->postJson('/api/payments', ['appointment_id' => $this->other->id, 'method' => 'pix'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->getJson("/api/payments/{$payment->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->putJson("/api/payments/{$payment->id}", ['amount' => 1])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/payments/{$payment->id}/status", ['status' => 'paid'])->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->assertSame(1, Payment::count());
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_admin_records_corrects_and_refunds_in_any_agenda(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/payments', ['appointment_id' => $this->other->id, 'method' => 'pix'])
            ->assertCreated()
            ->json('id');

        $this->patchJson("/api/payments/{$id}", ['amount' => 30])->assertOk()->assertJsonPath('amount', '30.00');
        $this->patchJson("/api/payments/{$id}/status", ['status' => 'refunded'])->assertOk()->assertJsonPath('status', 'refunded');

        $this->getJson('/api/payments')->assertOk()->assertJsonCount(1, 'data');
    }
}
