<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use App\Services\PaymentRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Regras dos pagamentos, testadas como admin, que pode tudo. As permissões de
 * cada perfil ficam no PaymentAuthorizationTest e a trava no MySQL no
 * PaymentConcurrencyTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 ao meio-dia, no fuso
 * America/Sao_Paulo. Os agendamentos são gravados direto no banco, já
 * concluídos, sem passar pelas regras da agenda.
 */
class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const LAST_SATURDAY = '2026-11-14';

    private const ALREADY_PAID = 'Este atendimento já tem pagamento registrado.';

    private const NOT_COMPLETED = 'Só é possível registrar o pagamento de um atendimento concluído.';

    private const NOTES_AFTER_DELETION = 'O cliente foi excluído: deixe as observações em branco.';

    /**
     * Para onde cada status pode ir. Escrita aqui à mão, para conferir o
     * PaymentStatus::transitions() contra a regra combinada.
     */
    private const VALID_TRANSITIONS = [
        'pending' => ['paid'],
        'paid' => ['refunded'],
        'refunded' => [],
    ];

    private Client $client;

    private Barber $barber;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        $this->actingAsAdmin();

        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    /**
     * Atendimento concluído de segunda às 10:00, Ana com o Pedro, gravado direto no banco.
     */
    private function appointment(array $overrides = []): Appointment
    {
        return Appointment::create(array_merge([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'completed',
            'price' => 35,
        ], $overrides));
    }

    /**
     * Pagamento gravado direto no banco, sem passar pelas regras: pago no Pix,
     * com o preço do agendamento.
     */
    private function payment(Appointment $appointment, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'appointment_id' => $appointment->id,
            'amount' => $appointment->price,
            'method' => 'pix',
            'status' => 'paid',
            'paid_at' => now(),
        ], $overrides));
    }

    private function newClient(string $name): Client
    {
        return Client::create(['name' => $name, 'email' => strtolower($name).'@barberflow.test']);
    }

    private function newBarber(string $name): Barber
    {
        return Barber::create(['name' => $name, 'email' => strtolower($name).'@barberflow.test']);
    }

    public function test_records_a_payment_with_the_price_of_the_appointment_as_paid(): void
    {
        $appointment = $this->appointment(['notes' => 'Prefere máquina 2']);

        // paid_at enviado é ignorado: quem preenche é o servidor.
        $response = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'method' => 'pix',
            'paid_at' => '2020-01-01 00:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment_id', $appointment->id)
            ->assertJsonPath('amount', '35.00')
            ->assertJsonPath('method', 'pix')
            ->assertJsonPath('method_label', 'Pix')
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('status_label', 'Pago')
            ->assertJsonPath('paid_at', now()->toJSON())
            ->assertJsonPath('notes', null)
            ->assertJsonPath('appointment.id', $appointment->id)
            ->assertJsonPath('appointment.date', self::MONDAY)
            ->assertJsonPath('appointment.start_time', '10:00:00')
            ->assertJsonPath('appointment.end_time', '10:30:00')
            ->assertJsonPath('appointment.client', ['id' => $this->client->id, 'name' => 'Ana'])
            ->assertJsonPath('appointment.barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('appointment.service', ['id' => $this->service->id, 'name' => 'Corte'])
            // As observações do agendamento não vão no pagamento.
            ->assertJsonMissingPath('appointment.notes')
            ->assertJsonMissingPath('appointment.price');

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('id'),
            'appointment_id' => $appointment->id,
            'method' => 'pix',
            'status' => 'paid',
            'paid_at' => self::MONDAY.' 12:00:00',
        ]);
    }

    public function test_amount_defaults_to_the_price_copied_on_the_appointment(): void
    {
        // O serviço ficou mais caro depois do agendamento: vale o preço do dia em que foi marcado.
        $this->service->update(['price' => 50]);
        $appointment = $this->appointment();

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cash', 'amount' => null])
            ->assertCreated()
            ->assertJsonPath('amount', '35.00')
            ->assertJsonPath('method_label', 'Dinheiro');
    }

    public static function amounts(): array
    {
        return [
            'outro valor' => [30, '30.00'],
            'com centavos' => ['42.5', '42.50'],
            'cortesia' => [0, '0.00'],
            'o máximo' => [999999.99, '999999.99'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_accepts_another_amount(int|float|string $amount, string $expected): void
    {
        $this->postJson('/api/payments', [
            'appointment_id' => $this->appointment()->id,
            'method' => 'debit_card',
            'amount' => $amount,
        ])
            ->assertCreated()
            ->assertJsonPath('amount', $expected)
            ->assertJsonPath('method_label', 'Cartão de débito');
    }

    public static function invalidFields(): array
    {
        return [
            'sem agendamento' => ['appointment_id', null],
            'agendamento que não existe' => ['appointment_id', 999],
            'agendamento que não é número' => ['appointment_id', 'abc'],
            'valor negativo' => ['amount', -1],
            'valor acima do máximo' => ['amount', 1000000],
            'valor que não é número' => ['amount', 'abc'],
            'valor com 3 casas' => ['amount', 10.555],
            'sem forma de pagamento' => ['method', null],
            'forma que não existe' => ['method', 'cheque'],
            'status que não existe' => ['status', 'foo'],
            'já nasce estornado' => ['status', 'refunded'],
            'observações com mais de 1000 caracteres' => ['notes', str_repeat('a', 1001)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_fields(string $field, mixed $value): void
    {
        $payload = array_merge(['appointment_id' => $this->appointment()->id, 'method' => 'pix'], [$field => $value]);

        $this->postJson('/api/payments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, Payment::count());
    }

    public function test_messages_are_in_portuguese(): void
    {
        $this->postJson('/api/payments', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', 'O campo agendamento é obrigatório.')
            ->assertJsonPath('errors.method.0', 'O campo forma de pagamento é obrigatório.')
            ->assertJsonMissingValidationErrors(['amount', 'status', 'notes']);

        $appointment = $this->appointment();

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix', 'amount' => 1000000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'O campo valor não pode ser maior que R$ 999.999,99.');

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix', 'amount' => '10.555'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'O campo valor pode ter no máximo 2 casas decimais.');

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cheque'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.method.0', 'O valor escolhido no campo forma de pagamento é inválido.');
    }

    public function test_accepts_notes_with_1000_characters(): void
    {
        $this->postJson('/api/payments', [
            'appointment_id' => $this->appointment()->id,
            'method' => 'pix',
            'notes' => str_repeat('a', 1000),
        ])->assertCreated();
    }

    public static function statusesThatAreNotCompleted(): array
    {
        return [
            'agendado' => ['scheduled'],
            'confirmado' => ['confirmed'],
            'em atendimento' => ['in_progress'],
            'cancelado' => ['cancelled'],
            'faltou' => ['no_show'],
        ];
    }

    #[DataProvider('statusesThatAreNotCompleted')]
    public function test_only_a_completed_appointment_takes_a_payment(string $status): void
    {
        $appointment = $this->appointment(['status' => $status]);

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', self::NOT_COMPLETED);

        $this->assertSame(0, Payment::count());
    }

    public function test_one_payment_per_appointment(): void
    {
        $appointment = $this->appointment();

        $id = $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix'])
            ->assertCreated()
            ->json('id');

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', self::ALREADY_PAID);

        // Nem depois do estorno: cada atendimento tem um pagamento só.
        $this->patchJson("/api/payments/{$id}/status", ['status' => 'refunded'])->assertOk();

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', self::ALREADY_PAID);

        $this->assertSame(1, Payment::count());
    }

    public function test_the_unique_index_is_the_last_barrier(): void
    {
        $appointment = $this->appointment();
        $inserted = false;

        // Outro pagamento entra logo depois da conferência, como se outro pedido tivesse passado sem a
        // trava: o insert esbarra no unique de appointment_id e a resposta é o mesmo 422, não um 500.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$inserted, $appointment) {
            if (! $inserted && str_contains($query->sql, 'exists') && str_contains($query->sql, 'payments')) {
                $inserted = true;

                DB::table('payments')->insert([
                    'appointment_id' => $appointment->id,
                    'amount' => 35,
                    'method' => 'cash',
                    'status' => 'paid',
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'pix'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', self::ALREADY_PAID);

        $this->assertTrue($inserted);
    }

    public function test_record_refuses_a_copy_changed_by_someone_else_meanwhile(): void
    {
        $appointment = $this->appointment();
        $stale = Appointment::find($appointment->id);

        // Outro pedido passou o agendamento para outro barbeiro depois da leitura que a policy autorizou.
        $appointment->update(['barber_id' => $this->newBarber('Joao')->id]);

        try {
            app(PaymentRecorder::class)->record($stale, ['method' => 'pix']);
            $this->fail('O registro devia recusar a cópia desatualizada.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('O agendamento mudou enquanto você registrava o pagamento. Recarregue e tente de novo.', $e->getMessage());
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_pending_payment_has_no_paid_at_until_it_is_paid(): void
    {
        $appointment = $this->appointment();

        $id = $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'credit_card', 'status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('status_label', 'Pendente')
            ->assertJsonPath('paid_at', null)
            ->json('id');

        $this->travelTo(Carbon::parse(self::TUESDAY.' 09:15:00'));

        $this->patchJson("/api/payments/{$id}/status", ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('status_label', 'Pago')
            ->assertJsonPath('paid_at', now()->toJSON())
            ->assertJsonPath('appointment.client', ['id' => $this->client->id, 'name' => 'Ana']);

        $this->assertDatabaseHas('payments', ['id' => $id, 'paid_at' => self::TUESDAY.' 09:15:00']);
    }

    public static function transitions(): array
    {
        $cases = [];

        foreach (self::VALID_TRANSITIONS as $from => $allowed) {
            foreach (array_keys(self::VALID_TRANSITIONS) as $to) {
                $cases["{$from} para {$to}"] = [$from, $to, in_array($to, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_status_changes_only_through_the_allowed_transitions(string $from, string $to, bool $allowed): void
    {
        $payment = $this->payment($this->appointment(), [
            'status' => $from,
            'paid_at' => $from === 'pending' ? null : now(),
        ]);

        $response = $this->patchJson("/api/payments/{$payment->id}/status", ['status' => $to]);

        if ($allowed) {
            $response->assertOk()
                ->assertJsonPath('status', $to)
                ->assertJsonPath('status_label', PaymentStatus::from($to)->label());

            return;
        }

        $fromLabel = PaymentStatus::from($from)->label();
        $toLabel = PaymentStatus::from($to)->label();

        $response->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "Não é possível mudar de {$fromLabel} para {$toLabel}.");

        $this->assertSame($from, $payment->fresh()->status->value);
    }

    public function test_catalogs_for_the_screens(): void
    {
        // Escritos aqui à mão, para conferir o que as telas vão receber dos enums.
        $this->assertSame([
            'cash' => ['label' => 'Dinheiro'],
            'pix' => ['label' => 'Pix'],
            'credit_card' => ['label' => 'Cartão de crédito'],
            'debit_card' => ['label' => 'Cartão de débito'],
        ], PaymentMethod::catalog());

        $this->assertSame([
            'pending' => ['label' => 'Pendente', 'transitions' => ['paid'], 'initial' => true],
            'paid' => ['label' => 'Pago', 'transitions' => ['refunded'], 'initial' => true],
            'refunded' => ['label' => 'Estornado', 'transitions' => [], 'initial' => false],
        ], PaymentStatus::catalog());
    }

    public function test_refund_keeps_the_date_it_was_paid(): void
    {
        $payment = $this->payment($this->appointment());
        $paidAt = $payment->paid_at->toJSON();

        $this->travelTo(Carbon::parse(self::TUESDAY.' 15:00:00'));

        $this->patchJson("/api/payments/{$payment->id}/status", ['status' => 'refunded'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('status_label', 'Estornado')
            ->assertJsonPath('paid_at', $paidAt)
            ->assertJsonPath('amount', '35.00');
    }

    public function test_status_change_reads_the_current_status_again(): void
    {
        $payment = $this->payment($this->appointment());
        $stale = Payment::find($payment->id);

        // Outro pedido estornou depois da leitura: estornar de novo parte de estornado.
        $payment->update(['status' => 'refunded']);

        try {
            app(PaymentRecorder::class)->changeStatus($stale, PaymentStatus::Refunded);
            $this->fail('A mudança de status devia partir do status atual.');
        } catch (ValidationException $e) {
            $this->assertSame(['status' => ['Não é possível mudar de Estornado para Estornado.']], $e->errors());
        }
    }

    public function test_rejects_an_unknown_or_missing_status(): void
    {
        $payment = $this->payment($this->appointment());

        $this->patchJson("/api/payments/{$payment->id}/status", ['status' => 'foo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/payments/{$payment->id}/status", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'O campo status é obrigatório.');
    }

    public function test_admin_corrects_amount_method_and_notes(): void
    {
        $payment = $this->payment($this->appointment());
        $other = $this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']);

        // Agendamento, status e paid_at não mudam pela correção.
        $this->putJson("/api/payments/{$payment->id}", [
            'amount' => 30,
            'method' => 'cash',
            'notes' => 'Desconto de aniversário',
            'appointment_id' => $other->id,
            'status' => 'refunded',
            'paid_at' => '2020-01-01 00:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('amount', '30.00')
            ->assertJsonPath('method', 'cash')
            ->assertJsonPath('method_label', 'Dinheiro')
            ->assertJsonPath('notes', 'Desconto de aniversário')
            ->assertJsonPath('appointment_id', $payment->appointment_id)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_at', $payment->paid_at->toJSON())
            ->assertJsonPath('appointment.date', self::MONDAY);

        $this->patchJson("/api/payments/{$payment->id}", ['notes' => null])
            ->assertOk()
            ->assertJsonPath('notes', null)
            ->assertJsonPath('amount', '30.00');
    }

    public function test_correction_rejects_invalid_fields(): void
    {
        $payment = $this->payment($this->appointment());

        $this->patchJson("/api/payments/{$payment->id}", [
            'amount' => null,
            'method' => 'cheque',
            'notes' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'method', 'notes']);

        $this->patchJson("/api/payments/{$payment->id}", ['amount' => 1000000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'O campo valor não pode ser maior que R$ 999.999,99.');

        $this->assertSame('35.00', $payment->fresh()->amount);
    }

    public function test_shows_a_payment(): void
    {
        $payment = $this->payment($this->appointment(), ['notes' => 'Pagou na saída']);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('id', $payment->id)
            ->assertJsonPath('notes', 'Pagou na saída')
            ->assertJsonPath('appointment.service', ['id' => $this->service->id, 'name' => 'Corte']);
    }

    public function test_returns_404_for_unknown_payment(): void
    {
        $this->getJson('/api/payments/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_payments_are_not_deleted_refunding_is_a_status(): void
    {
        $payment = $this->payment($this->appointment());

        $this->deleteJson("/api/payments/{$payment->id}")
            ->assertStatus(405)
            ->assertExactJson(['message' => 'Método não permitido para esta rota.']);

        $this->assertModelExists($payment);
    }

    public function test_lists_the_newest_appointments_first_with_pagination(): void
    {
        $saturday = $this->payment($this->appointment(['date' => self::LAST_SATURDAY]));
        $mondayMorning = $this->payment($this->appointment(['start_time' => '09:00:00', 'end_time' => '09:30:00']));
        $mondayLater = $this->payment($this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']));

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $mondayLater->id)
            ->assertJsonPath('data.1.id', $mondayMorning->id)
            ->assertJsonPath('data.2.id', $saturday->id)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'appointment_id', 'amount', 'method', 'method_label', 'status', 'status_label', 'paid_at', 'notes',
                    'appointment' => ['id', 'date', 'start_time', 'end_time', 'client' => ['id', 'name'], 'barber' => ['id', 'name'], 'service' => ['id', 'name']],
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'totals' => ['pending' => ['payments', 'total'], 'paid' => ['payments', 'total'], 'refunded' => ['payments', 'total']],
            ]);

        $response = $this->getJson('/api/payments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $saturday->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertStringContainsString('per_page=2', $response->json('links.prev'));
    }

    public function test_same_time_with_two_barbers_is_ordered_by_the_newest_payment(): void
    {
        $first = $this->payment($this->appointment());
        $second = $this->payment($this->appointment(['barber_id' => $this->newBarber('Joao')->id, 'client_id' => $this->newClient('Bruno')->id]));

        $this->assertListed('', [$second, $first]);
    }

    public function test_items_bring_only_the_summary_of_the_appointment(): void
    {
        $this->client->update(['phone' => '67911112222']);
        $this->payment($this->appointment(['notes' => 'Alergia a lâmina X']));

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonPath('data.0.appointment.client', ['id' => $this->client->id, 'name' => 'Ana'])
            ->assertJsonPath('data.0.appointment.barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('data.0.appointment.service', ['id' => $this->service->id, 'name' => 'Corte'])
            ->assertJsonMissingPath('data.0.appointment.notes')
            ->assertJsonMissingPath('data.0.appointment.client.phone')
            ->assertJsonMissingPath('data.0.appointment.client.email');
    }

    public function test_filters_by_period_method_status_barber_and_client(): void
    {
        $joao = $this->newBarber('Joao');
        $bruno = $this->newClient('Bruno');

        $saturday = $this->payment($this->appointment(['date' => self::LAST_SATURDAY]), ['method' => 'cash']);
        $monday = $this->payment($this->appointment(['barber_id' => $joao->id]), ['status' => 'pending', 'paid_at' => null]);
        $tuesday = $this->payment($this->appointment(['date' => self::TUESDAY, 'client_id' => $bruno->id]), ['status' => 'refunded']);

        $this->assertListed('from='.self::MONDAY, [$tuesday, $monday]);
        $this->assertListed('to='.self::MONDAY, [$monday, $saturday]);
        $this->assertListed('from='.self::MONDAY.'&to='.self::MONDAY, [$monday]);
        $this->assertListed('method=cash', [$saturday]);
        $this->assertListed('method=pix', [$tuesday, $monday]);
        $this->assertListed('status=pending', [$monday]);
        $this->assertListed('status=refunded', [$tuesday]);
        $this->assertListed("barber_id={$joao->id}", [$monday]);
        $this->assertListed("client_id={$bruno->id}", [$tuesday]);
        $this->assertListed("barber_id={$this->barber->id}&status=paid", [$saturday]);
        $this->assertListed('method=debit_card', []);
    }

    public function test_totals_of_each_status_cover_every_page_with_the_same_filters(): void
    {
        $this->payment($this->appointment(['date' => self::LAST_SATURDAY]));
        $this->payment($this->appointment(), ['amount' => 40.5]);
        $this->payment($this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']), ['status' => 'pending', 'paid_at' => null, 'amount' => 55]);
        $this->payment($this->appointment(['start_time' => '12:00:00', 'end_time' => '12:30:00']), ['status' => 'refunded', 'amount' => 25]);
        $this->payment($this->appointment(['barber_id' => $this->newBarber('Joao')->id, 'client_id' => $this->newClient('Bruno')->id]), ['amount' => 30]);

        // A página tem 1 item, mas o total olha todas.
        $this->getJson('/api/payments?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('totals', [
                'pending' => ['payments' => 1, 'total' => '55.00'],
                'paid' => ['payments' => 3, 'total' => '105.50'],
                'refunded' => ['payments' => 1, 'total' => '25.00'],
            ]);

        // Com os mesmos filtros da lista: a segunda do Pedro.
        $this->getJson('/api/payments?from='.self::MONDAY."&barber_id={$this->barber->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('totals', [
                'pending' => ['payments' => 1, 'total' => '55.00'],
                'paid' => ['payments' => 1, 'total' => '40.50'],
                'refunded' => ['payments' => 1, 'total' => '25.00'],
            ]);

        $this->getJson('/api/payments?status=paid&method=cash')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('totals.paid', ['payments' => 0, 'total' => '0.00']);
    }

    /**
     * @param  list<Payment>  $expected
     */
    private function assertListed(string $query, array $expected): void
    {
        $this->assertSame(
            array_map(fn (Payment $payment) => $payment->id, $expected),
            array_column($this->getJson("/api/payments?{$query}")->assertOk()->json('data'), 'id'),
            $query,
        );
    }

    public static function invalidFilters(): array
    {
        return [
            'início do período em outro formato' => ['from=ontem', 'from'],
            'fim antes do início' => ['from=2026-11-17&to=2026-11-16', 'to'],
            'forma que não existe' => ['method=cheque', 'method'],
            'status que não existe' => ['status=foo', 'status'],
            'barbeiro que não é número' => ['barber_id=abc', 'barber_id'],
            'cliente que não é número' => ['client_id=abc', 'client_id'],
            'mais de 100 por página' => ['per_page=101', 'per_page'],
            'página zero' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_rejects_invalid_filters(string $query, string $field): void
    {
        $this->getJson("/api/payments?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_lists_without_one_query_per_payment(): void
    {
        $this->payment($this->appointment());

        $queriesForOne = $this->countQueries(fn () => $this->getJson('/api/payments')->assertJsonCount(1, 'data'));

        foreach (['Bruno', 'Carla', 'Diego'] as $index => $name) {
            $start = 11 + $index;
            $service = Service::create(['name' => "Serviço {$name}", 'duration' => 30, 'price' => 40]);

            $this->payment($this->appointment([
                'client_id' => $this->newClient($name)->id,
                'barber_id' => $this->newBarber("Barbeiro{$name}")->id,
                'service_id' => $service->id,
                'start_time' => "{$start}:00:00",
                'end_time' => "{$start}:30:00",
            ]));
        }

        $queriesForFour = $this->countQueries(fn () => $this->getJson('/api/payments')->assertJsonCount(4, 'data'));

        $this->assertSame($queriesForOne, $queriesForFour);
    }

    public function test_appointment_brings_the_summary_of_the_payment(): void
    {
        $paid = $this->appointment();
        $payment = $this->payment($paid, ['notes' => 'Pagou na saída']);
        $unpaid = $this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']);

        $this->getJson("/api/appointments/{$paid->id}")
            ->assertOk()
            ->assertJsonPath('payment', ['id' => $payment->id, 'status' => 'paid', 'status_label' => 'Pago']);

        $this->getJson("/api/appointments/{$unpaid->id}")
            ->assertOk()
            ->assertJsonPath('payment', null);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.payment', ['id' => $payment->id, 'status' => 'paid', 'status_label' => 'Pago'])
            ->assertJsonPath('data.1.payment', null);
    }

    public function test_appointments_list_the_payment_without_one_query_per_appointment(): void
    {
        $this->payment($this->appointment());

        $queriesForOne = $this->countQueries(fn () => $this->getJson('/api/appointments')->assertJsonCount(1, 'data'));

        foreach ([11, 12, 13] as $start) {
            $this->payment($this->appointment(['start_time' => "{$start}:00:00", 'end_time' => "{$start}:30:00"]));
        }

        $queriesForFour = $this->countQueries(fn () => $this->getJson('/api/appointments')->assertJsonCount(4, 'data'));

        $this->assertSame($queriesForOne, $queriesForFour);
    }

    public function test_without_payment_lists_completed_appointments_still_without_payment(): void
    {
        $waiting = $this->appointment(['start_time' => '09:00:00', 'end_time' => '09:30:00']);
        $this->payment($this->appointment());
        $this->payment($this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']), ['status' => 'pending', 'paid_at' => null]);
        $this->payment($this->appointment(['start_time' => '12:00:00', 'end_time' => '12:30:00']), ['status' => 'refunded']);
        $this->appointment(['date' => self::TUESDAY, 'status' => 'scheduled']);
        $this->appointment(['start_time' => '13:00:00', 'end_time' => '13:30:00', 'status' => 'no_show']);

        $this->getJson('/api/appointments?without_payment=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $waiting->id)
            ->assertJsonPath('data.0.payment', null);

        // Sem o filtro (ou com 0), a lista é a de sempre.
        $this->getJson('/api/appointments?without_payment=0')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_without_payment_combines_with_the_other_filters(): void
    {
        $joao = $this->newBarber('Joao');
        $monday = $this->appointment();
        $tuesday = $this->appointment(['date' => self::TUESDAY]);
        $joaos = $this->appointment(['barber_id' => $joao->id, 'client_id' => $this->newClient('Bruno')->id]);

        $this->assertWaiting('', [$monday, $joaos, $tuesday]);
        $this->assertWaiting('date='.self::TUESDAY, [$tuesday]);
        $this->assertWaiting("barber_id={$joao->id}", [$joaos]);
        $this->assertWaiting("client_id={$this->client->id}", [$monday, $tuesday]);
        $this->assertWaiting('status=completed', [$monday, $joaos, $tuesday]);
        $this->assertWaiting('status=scheduled', []);
    }

    /**
     * @param  list<Appointment>  $expected
     */
    private function assertWaiting(string $query, array $expected): void
    {
        $this->assertSame(
            array_map(fn (Appointment $appointment) => $appointment->id, $expected),
            array_column($this->getJson("/api/appointments?without_payment=1&{$query}")->assertOk()->json('data'), 'id'),
            $query,
        );
    }

    public function test_without_payment_must_be_true_or_false(): void
    {
        $this->getJson('/api/appointments?without_payment=talvez')
            ->assertUnprocessable()
            ->assertJsonPath('errors.without_payment.0', 'O campo sem pagamento deve ser verdadeiro ou falso.');
    }

    public function test_deleting_a_client_also_erases_the_notes_of_the_payments(): void
    {
        // Observação é texto livre e pode ter dado pessoal: sai junto com o cadastro (LGPD).
        $own = $this->payment($this->appointment(['notes' => 'Ana Souza, tel 67999990000']), ['notes' => 'Pagou com o cartão da mãe, Maria Souza']);
        $refunded = $this->payment($this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']), ['status' => 'refunded', 'notes' => 'Estorno pedido pelo telefone 67999990000']);
        $other = $this->payment($this->appointment(['client_id' => $this->newClient('Bruno')->id, 'start_time' => '12:00:00', 'end_time' => '12:30:00']), ['notes' => 'Troco em moedas']);

        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->getJson("/api/payments/{$own->id}")
            ->assertOk()
            ->assertJsonPath('notes', null)
            ->assertJsonPath('amount', '35.00')
            ->assertJsonPath('appointment.client', ['id' => $this->client->id, 'name' => 'Cliente removido']);

        $this->assertNull($refunded->fresh()->notes);
        $this->assertSame('refunded', $refunded->fresh()->status->value);

        // As observações dos pagamentos dos outros clientes continuam.
        $this->assertSame('Troco em moedas', $other->fresh()->notes);
    }

    public function test_a_payment_can_be_recorded_for_a_deleted_client(): void
    {
        // O histórico continua: o atendimento concluído de quem foi excluído ainda pode ser pago.
        $appointment = $this->appointment(['date' => self::LAST_SATURDAY]);
        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('appointment.client.name', 'Cliente removido');
    }

    public function test_a_payment_of_a_deleted_client_takes_no_notes(): void
    {
        // A exclusão apagou as observações (LGPD): um texto novo com dado pessoal voltaria a ficar ligado
        // ao cadastro e nada mais o apagaria. Sem observações, o registro continua permitido.
        $appointment = $this->appointment(['date' => self::LAST_SATURDAY]);
        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'method' => 'cash',
            'notes' => 'Ana Souza pagou com o cartão da mãe, tel 67999990000',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.notes.0', self::NOTES_AFTER_DELETION);

        $this->assertSame(0, Payment::count());

        // Observações só com espaços chegam vazias (null) e passam.
        $this->postJson('/api/payments', ['appointment_id' => $appointment->id, 'method' => 'cash', 'notes' => '   '])
            ->assertCreated()
            ->assertJsonPath('notes', null);
    }

    public function test_correction_of_a_payment_of_a_deleted_client_takes_no_notes(): void
    {
        // O modal Corrigir aberto antes da exclusão ainda tem as observações antigas: salvar depois dela
        // não grava de novo o que a exclusão apagou.
        $payment = $this->payment($this->appointment(['date' => self::LAST_SATURDAY]), ['notes' => 'Pagou com o cartão da mãe, Maria Souza']);
        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->putJson("/api/payments/{$payment->id}", ['amount' => 30, 'method' => 'cash', 'notes' => 'Pagou com o cartão da mãe, Maria Souza'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.notes.0', self::NOTES_AFTER_DELETION);

        $this->assertSame('35.00', $payment->fresh()->amount);
        $this->assertNull($payment->fresh()->notes);

        // Sem observações, a correção passa.
        $this->putJson("/api/payments/{$payment->id}", ['amount' => 30, 'method' => 'cash', 'notes' => null])
            ->assertOk()
            ->assertJsonPath('amount', '30.00')
            ->assertJsonPath('method', 'cash')
            ->assertJsonPath('notes', null);
    }

    public function test_the_deleted_client_is_read_again_after_the_lock(): void
    {
        // As cópias que o controller autorizou ainda trazem o cliente sem exclusão. A exclusão termina
        // antes da trava, como quando o pedido espera por ela no MySQL: a conferência lê o cliente de novo.
        $waiting = Appointment::with('client')->find($this->appointment(['date' => self::LAST_SATURDAY])->id);
        $paid = $this->payment($this->appointment(['date' => self::LAST_SATURDAY, 'start_time' => '11:00:00', 'end_time' => '11:30:00']));
        $stalePayment = Payment::with('appointment.client')->find($paid->id);

        $this->client->anonymizeAndDelete();
        $recorder = app(PaymentRecorder::class);

        try {
            $recorder->record($waiting, ['method' => 'pix', 'notes' => 'Ana Souza, tel 67999990000']);
            $this->fail('O registro devia recusar as observações do cliente excluído.');
        } catch (ValidationException $e) {
            $this->assertSame(['notes' => [self::NOTES_AFTER_DELETION]], $e->errors());
        }

        try {
            $recorder->correct($stalePayment, ['notes' => 'Ana Souza, tel 67999990000']);
            $this->fail('A correção devia recusar as observações do cliente excluído.');
        } catch (ValidationException $e) {
            $this->assertSame(['notes' => [self::NOTES_AFTER_DELETION]], $e->errors());
        }

        $this->assertSame(1, Payment::count());
        $this->assertNull($paid->fresh()->notes);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
