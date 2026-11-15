<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Services\PaymentRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pagamentos dos atendimentos concluídos, no máximo um por agendamento.
 * Permissões na PaymentPolicy: o admin faz tudo; o barbeiro registra e vê os
 * pagamentos dos agendamentos dele e marca como pago um pendente; o cliente só
 * vê os próprios. As regras ficam no PaymentRecorder.
 *
 * Sem destroy: estornar é mudar o status (PATCH /api/payments/{id}/status).
 * Cada pagamento sai com o agendamento resumido (data, horário e o cliente, o
 * barbeiro e o serviço só com id e nome).
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentRecorder $recorder) {}

    /**
     * Lista paginada, do atendimento mais recente para o mais antigo (data,
     * horário e id). Filtros opcionais: ?from= e ?to= (Y-m-d, pela data do
     * atendimento), ?method=, ?status=, ?barber_id=, ?client_id=, ?per_page=
     * (1 a 100, padrão 15) e ?page= (1 a 100000). O barbeiro vê só os da agenda
     * dele e o cliente só os próprios, seja qual for o filtro.
     *
     * Além de data, links e meta, sai totals: a quantidade e a soma dos valores
     * de cada status com os mesmos filtros, em todas as páginas, para a tela
     * mostrar o total sem ler a lista inteira.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Payment::class);

        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'barber_id' => 'nullable|integer',
            'client_id' => 'nullable|integer',
        ]);

        // O join com o agendamento serve aos filtros e à ordem.
        $query = Payment::query()
            ->visibleTo($request->user())
            ->join('appointments', 'appointments.id', '=', 'payments.appointment_id')
            ->when(isset($filters['from']), fn ($query) => $query->where('appointments.date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('appointments.date', '<=', $filters['to']))
            ->when(isset($filters['barber_id']), fn ($query) => $query->where('appointments.barber_id', $filters['barber_id']))
            ->when(isset($filters['client_id']), fn ($query) => $query->where('appointments.client_id', $filters['client_id']))
            ->when(isset($filters['method']), fn ($query) => $query->where('payments.method', $filters['method']))
            ->when(isset($filters['status']), fn ($query) => $query->where('payments.status', $filters['status']));

        $totals = $query->clone()
            ->toBase()
            ->groupBy('payments.status')
            ->get(['payments.status', DB::raw('count(*) as quantity'), DB::raw('sum(payments.amount) as total')])
            ->keyBy('status');

        // O select deixa no model só as colunas do pagamento (o join traria as do agendamento).
        $payments = $query->select('payments.*')
            ->with(Payment::APPOINTMENT_SUMMARY)
            ->orderByDesc('appointments.date')
            ->orderByDesc('appointments.start_time')
            ->orderByDesc('payments.id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString()
            ->through(fn (Payment $payment) => $payment->summarizeAppointment());

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection($payments)->additional([
            'totals' => collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $status) => [$status->value => [
                'payments' => (int) ($totals->get($status->value)?->quantity ?? 0),
                'total' => number_format((float) ($totals->get($status->value)?->total ?? 0), 2, '.', ''),
            ]])->all(),
        ]);
    }

    /**
     * Registra o pagamento de um atendimento concluído. Sem amount, vale o price
     * do agendamento; sem status, pago (o paid_at é do servidor). O barbeiro
     * registra só nos agendamentos dele; com o de outro, 403.
     *
     * Com use_loyalty_reward true, o pagamento usa um prêmio da fidelidade do
     * cliente: o desconto vem da config e o amount é calculado (enviado junto,
     * 422). Sem prêmio disponível, 422 em use_loyalty_reward.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Payment::class);

        $validated = $request->validate([
            'appointment_id' => 'required|integer|exists:appointments,id',
            // Cabe no decimal(8,2). Com o prêmio, o valor sai do desconto.
            'amount' => 'prohibited_if_accepted:use_loyalty_reward|nullable|numeric|decimal:0,2|min:0|max:999999.99',
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)->only(PaymentStatus::initial())],
            'notes' => 'nullable|string|max:1000',
            'use_loyalty_reward' => 'nullable|boolean',
        ], [
            'amount.prohibited_if_accepted' => PaymentRecorder::AMOUNT_WITH_REWARD,
        ]);

        $appointment = Appointment::findOrFail($validated['appointment_id']);

        Gate::authorize('recordFor', [Payment::class, $appointment]);

        $payment = $this->recorder->record($appointment, $validated);

        return response()->json($payment->load(Payment::APPOINTMENT_SUMMARY)->summarizeAppointment(), 201);
    }

    public function show(Payment $payment)
    {
        Gate::authorize('view', $payment);

        return $payment->load(Payment::APPOINTMENT_SUMMARY)->summarizeAppointment();
    }

    /**
     * Correção de valor, forma e observações, só pelo admin. O agendamento, o
     * status e o paid_at não mudam aqui: o status tem rota própria. O pagamento
     * de um cliente excluído não leva observações (PaymentRecorder::correct()).
     */
    public function update(Request $request, Payment $payment)
    {
        Gate::authorize('update', $payment);

        $validated = $request->validate([
            'amount' => 'sometimes|required|numeric|decimal:0,2|min:0|max:999999.99',
            'method' => ['sometimes', 'required', Rule::enum(PaymentMethod::class)],
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->recorder->correct($payment, $validated)
            ->load(Payment::APPOINTMENT_SUMMARY)
            ->summarizeAppointment();
    }

    /**
     * PATCH /api/payments/{payment}/status com {"status": "..."}. As transições
     * permitidas ficam no PaymentStatus: pendente para pago e pago para
     * estornado. O barbeiro só marca como pago; o estorno é do admin.
     */
    public function updateStatus(Request $request, Payment $payment)
    {
        Gate::authorize('view', $payment);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(PaymentStatus::class)],
        ]);

        $status = PaymentStatus::from($validated['status']);

        Gate::authorize('changeStatus', [$payment, $status]);

        return $this->recorder->changeStatus($payment, $status)
            ->load(Payment::APPOINTMENT_SUMMARY)
            ->summarizeAppointment();
    }
}
