<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/barbers/{barber}/available-slots?date=Y-m-d&service_id=
 *
 * Horários livres do barbeiro no dia para o serviço, como {"data": ["09:00", "09:30", ...]}.
 * Todos os perfis com o e-mail confirmado usam (o cliente precisa para agendar),
 * pela mesma regra de quem vê o barbeiro (BarberPolicy::view). Barbeiro
 * excluído dá 404, como no GET do barbeiro. Dia sem expediente, que já passou
 * ou depois de BOOKING_DAYS_AHEAD dias devolve a lista vazia.
 *
 * Ao remarcar, &appointment_id= faz o horário do próprio agendamento contar como
 * livre (a remarcação também não conta com ele). Só para quem pode remarcá-lo
 * (AppointmentPolicy::update); os outros recebem 403.
 */
class AvailableSlotController extends Controller
{
    public function __construct(private readonly AppointmentScheduler $scheduler) {}

    public function __invoke(Request $request, Barber $barber): JsonResponse
    {
        Gate::authorize('view', $barber);

        // Para o cliente, serviço inativo ou excluído é como um id que não existe (a mesma regra do POST).
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'service_id' => ['required', 'integer', AppointmentController::bookableService()],
            'appointment_id' => 'nullable|integer|exists:appointments,id',
        ]);

        // withTrashed: para a barbearia, serviço excluído ou inativo vira 422 com a mesma mensagem do agendamento.
        $service = Service::withTrashed()->findOrFail($validated['service_id']);

        $rescheduling = isset($validated['appointment_id']) ? Appointment::findOrFail($validated['appointment_id']) : null;

        if ($rescheduling !== null) {
            Gate::authorize('update', $rescheduling);
        }

        return response()->json([
            'data' => $this->scheduler->availableSlots($barber, $service, $validated['date'], $rescheduling),
        ]);
    }
}
