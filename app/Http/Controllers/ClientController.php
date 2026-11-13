<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientController extends Controller
{
    /**
     * Lista paginada e em ordem de nome. Filtros opcionais: ?search= (nome ou
     * e-mail), ?per_page= (1 a 100, padrão 15) e ?page= (1 a 100000).
     */
    public function index(Request $request)
    {
        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $search = $filters['search'] ?? null;

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection(
            Client::query()
                ->when($search !== null, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->orderBy('id')
                ->paginate((int) ($filters['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(Request $request)
    {
        // O domínio barberflow.invalid é reservado para o e-mail de quem foi excluído.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|not_regex:/@barberflow\.invalid$/i|unique:clients,email',
            'phone' => 'nullable|string|max:20',
        ]);

        return response()->json(Client::create($validated), 201);
    }

    public function show(Client $client)
    {
        return $client;
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|not_regex:/@barberflow\.invalid$/i|unique:clients,email,'.$client->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $client->update($validated);

        return $client;
    }

    /**
     * Exclusão lógica com anonimização (LGPD): a linha fica para o histórico,
     * mas sem nome, e-mail e telefone do cliente.
     */
    public function destroy(Client $client)
    {
        $client->anonymizeAndDelete();

        return response()->noContent();
    }
}
