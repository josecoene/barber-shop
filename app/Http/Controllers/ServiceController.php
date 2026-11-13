<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceController extends Controller
{
    /**
     * Lista paginada e em ordem de nome. Filtros opcionais: ?search= (nome),
     * ?status=active|inactive, ?per_page= (1 a 100, padrão 15) e ?page= (1 a 100000).
     */
    public function index(Request $request)
    {
        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'duration' => 'required|integer|min:5|max:480', // duração em minutos
            'price' => 'required|numeric|min:0|max:999999.99', // cabe no decimal(8,2)
            'status' => 'sometimes|required|string|in:active,inactive', // sem status, fica active
        ]);

        return response()->json(Service::create($validated), 201);
    }

    public function show(Service $service)
    {
        return $service;
    }

    public function update(Request $request, Service $service)
    {
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
     * Exclusão lógica: a linha fica no banco com deleted_at preenchido.
     */
    public function destroy(Service $service)
    {
        $service->delete();

        return response()->noContent();
    }
}
