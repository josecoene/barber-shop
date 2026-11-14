<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Agenda. Permissões na AppointmentPolicy: o admin faz tudo; o barbeiro vê e
 * mexe só nos agendamentos dele; o cliente agenda para si, vê os próprios e só
 * cancela. As regras de horário ficam no AppointmentScheduler.
 *
 * Sem destroy: cancelar é mudar o status (PATCH /api/appointments/{id}/status).
 * Cada agendamento sai com o cliente, o barbeiro e o serviço resumidos (id e nome).
 */
class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentScheduler $scheduler) {}

    /**
     * Lista paginada em ordem de data e horário. Filtros opcionais: ?date=,
     * ?from= e ?to= (Y-m-d), ?barber_id=, ?client_id=, ?status=, ?per_page= (1 a
     * 100, padrão 15) e ?page= (1 a 100000). O barbeiro vê só os da agenda dele
     * e o cliente só os próprios, seja qual for o filtro.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Appointment::class);

        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'barber_id' => 'nullable|integer',
            'client_id' => 'nullable|integer',
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
        ]);

        $appointments = Appointment::query()
            ->visibleTo($request->user())
            ->with(Appointment::SUMMARY_RELATIONS)
            ->when(isset($filters['date']), fn ($query) => $query->where('date', $filters['date']))
            ->when(isset($filters['from']), fn ($query) => $query->where('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('date', '<=', $filters['to']))
            ->when(isset($filters['barber_id']), fn ($query) => $query->where('barber_id', $filters['barber_id']))
            ->when(isset($filters['client_id']), fn ($query) => $query->where('client_id', $filters['client_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString()
            ->through(fn (Appointment $appointment) => $appointment->summarizeRelations());

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection($appointments);
    }

    /**
     * Agenda com o término calculado e o preço copiado do serviço. O barbeiro
     * agenda só na própria agenda e o cliente só para si: sem o campo, vale o id
     * do cadastro deles; com o id de outro, 403. O cliente também tem o limite
     * de agendamentos ativos (CLIENT_MAX_ACTIVE_APPOINTMENTS).
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Appointment::class);

        $this->fillOwnRecord($request);

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'barber_id' => 'required|integer|exists:barbers,id',
            'service_id' => ['required', 'integer', self::bookableService()],
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'notes' => 'nullable|string|max:1000',
        ]);

        Gate::authorize('scheduleFor', [Appointment::class, (int) $validated['barber_id'], (int) $validated['client_id']]);

        $appointment = $this->scheduler->schedule($validated, byClient: $request->user()->isClient());

        return response()->json($appointment->load(Appointment::SUMMARY_RELATIONS)->summarizeRelations(), 201);
    }

    public function show(Appointment $appointment)
    {
        Gate::authorize('view', $appointment);

        return $appointment->load(Appointment::SUMMARY_RELATIONS)->summarizeRelations();
    }

    /**
     * Remarca (date, start_time, barber_id e service_id) e muda as observações
     * (notes), só com o status agendado ou confirmado. O cliente e o status não
     * mudam aqui: o status tem rota própria.
     */
    public function update(Request $request, Appointment $appointment)
    {
        Gate::authorize('update', $appointment);

        $validated = $request->validate([
            'barber_id' => 'sometimes|required|integer|exists:barbers,id',
            'service_id' => 'sometimes|required|integer|exists:services,id',
            'date' => 'sometimes|required|date_format:Y-m-d',
            'start_time' => 'sometimes|required|date_format:H:i,H:i:s',
            'notes' => 'nullable|string|max:1000',
        ]);

        // O barbeiro não passa o agendamento para a agenda de outro barbeiro.
        if (isset($validated['barber_id'])) {
            Gate::authorize('scheduleFor', [Appointment::class, (int) $validated['barber_id'], $appointment->client_id]);
        }

        return $this->scheduler->reschedule($appointment, $validated)
            ->load(Appointment::SUMMARY_RELATIONS)
            ->summarizeRelations();
    }

    /**
     * PATCH /api/appointments/{appointment}/status com {"status": "..."}. As
     * transições permitidas ficam no AppointmentStatus. O cliente só cancela, e
     * só até CLIENT_CANCEL_MIN_HOURS horas antes do início.
     */
    public function updateStatus(Request $request, Appointment $appointment)
    {
        Gate::authorize('view', $appointment);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
        ]);

        $status = AppointmentStatus::from($validated['status']);

        Gate::authorize('changeStatus', [$appointment, $status]);

        return $this->scheduler->changeStatus($appointment, $status, byClient: $request->user()->isClient())
            ->load(Appointment::SUMMARY_RELATIONS)
            ->summarizeRelations();
    }

    /**
     * Regra do service_id ao agendar e ao buscar os horários livres
     * (AvailableSlotController). Para quem não vê serviços inativos (o
     * cliente), serviço inativo ou excluído é como um id que não existe, com a
     * mesma mensagem, igual ao 404 do GET /api/services/{id}. A barbearia vê o
     * motivo: o AppointmentScheduler responde que o serviço está inativo ou
     * foi excluído.
     */
    public static function bookableService(): Exists
    {
        $rule = Rule::exists('services', 'id');

        return Gate::allows('viewInactive', Service::class)
            ? $rule
            : $rule->where('status', 'active')->whereNull('deleted_at');
    }

    /**
     * Barbeiro e cliente não precisam mandar o próprio id: sem o campo (ou com
     * null), vale o do cadastro ligado ao login. O id de outro continua no
     * pedido, e o scheduleFor da policy recusa com 403.
     */
    private function fillOwnRecord(Request $request): void
    {
        $user = $request->user();

        if ($user->isBarber() && $request->input('barber_id') === null) {
            $request->merge(['barber_id' => $user->barber->id]);
        }

        if ($user->isClient() && $request->input('client_id') === null) {
            $request->merge(['client_id' => $user->client->id]);
        }
    }
}
