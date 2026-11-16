<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Permissões na ServicePolicy: o admin faz tudo, o barbeiro só vê e o cliente vê
 * só os serviços ativos.
 */
class ServiceController extends Controller
{
    /**
     * Lista paginada e em ordem de nome. Filtros opcionais: ?search= (nome),
     * ?status=active|inactive, ?per_page= (1 a 100, padrão 15) e ?page= (1 a 100000).
     * Para o cliente, o status fica sempre active.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Service::class);

        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $search = $filters['search'] ?? null;
        $status = Gate::allows('viewInactive', Service::class) ? ($filters['status'] ?? null) : 'active';

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection(
            Service::query()
                ->when($search !== null, fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Service::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|integer|min:5|max:480', // duração em minutos
            'price' => 'required|numeric|min:0|max:999999.99', // cabe no decimal(8,2)
            'status' => 'sometimes|required|string|in:active,inactive', // sem status, fica active
        ]);

        return response()->json(Service::create($validated), 201);
    }

    /**
     * Serviço inativo dá 404 para o cliente, como um id que não existe.
     */
    public function show(Service $service)
    {
        Gate::authorize('view', $service);

        return $service;
    }

    public function update(Request $request, Service $service)
    {
        Gate::authorize('update', $service);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'duration' => 'sometimes|required|integer|min:5|max:480',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'status' => 'sometimes|required|string|in:active,inactive',
        ]);

        $service->update($validated);

        return $service;
    }

    /**
     * Exclusão lógica: a linha fica no banco com deleted_at preenchido. Com
     * agendamento de hoje em diante ainda ativo, 409 (cancele ou remarque antes).
     */
    public function destroy(Service $service)
    {
        Gate::authorize('delete', $service);

        DB::transaction(function () use ($service) {
            $service->ensureNoUpcomingAppointments();
            $service->delete();
        });

        return response()->noContent();
    }
}
