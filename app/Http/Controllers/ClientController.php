<?php

namespace App\Http\Controllers;

use App\Exceptions\EmailTakenException;
use App\Models\Client;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Permissões na ClientPolicy: o admin faz tudo; o barbeiro vê, cria e edita; o
 * cliente não lista os outros e só vê e edita (nome e telefone) o próprio
 * cadastro. Só o admin exclui. A fidelidade (visitas e prêmios) fica no
 * LoyaltyService.
 */
class ClientController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /**
     * Lista paginada e em ordem de nome. Filtros opcionais: ?search= (nome ou
     * e-mail), ?per_page= (1 a 100, padrão 15) e ?page= (1 a 100000). Cada
     * cliente traz a fidelidade em loyalty (o mesmo resumo do GET
     * /api/clients/{client}/loyalty), contada na própria consulta da página,
     * sem uma consulta por cliente.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Client::class);

        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $search = $filters['search'] ?? null;

        $clients = $this->loyalty->withCounts(Client::query())
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
            ->through(fn (Client $client) => $this->loyalty->attachSummary($client));

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection($clients);
    }

    /**
     * GET /api/clients/{client}/loyalty: visitas, visitas por prêmio, progresso
     * (ex.: 7 de 10), prêmios ganhos, usados e disponíveis e o percentual do
     * desconto. Quem pode é o mesmo do ver cliente (ClientPolicy::view): o
     * admin e o barbeiro veem qualquer cliente, e o cliente só o próprio.
     *
     * Com ?appointment_id= (um agendamento deste cliente), esse atendimento fica
     * fora das visitas: é a conta do pagamento dele com o prêmio, a mesma do
     * POST /api/payments, para o modal de pagamento mostrar se o prêmio vale.
     * O agendamento é buscado só entre os deste cliente que o usuário pode ver
     * (Appointment::scopeVisibleTo, a mesma regra da AppointmentPolicy::view):
     * o que não existe, o de outro cliente e o de uma agenda que o usuário não
     * vê dão o mesmo 422, para a resposta não contar ao barbeiro quais
     * agendamentos de outras agendas são deste cliente.
     */
    public function loyalty(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $validated = $request->validate([
            'appointment_id' => 'nullable|integer',
        ]);

        $paying = null;

        if (isset($validated['appointment_id'])) {
            $paying = $client->appointments()->visibleTo($request->user())->find($validated['appointment_id']);

            if ($paying === null) {
                throw ValidationException::withMessages(['appointment_id' => 'O agendamento informado não foi encontrado entre os deste cliente.']);
            }
        }

        return response()->json([
            'client_id' => $client->id,
            ...$this->loyalty->summary($client, $paying?->id),
        ]);
    }

    /**
     * O e-mail repetido que passou pela regra unique (outro cadastro com o mesmo
     * e-mail no mesmo instante) sai como o mesmo 422 dela (EmailTakenException).
     * O insert vai dentro de uma transação: se a espera pela resposta do banco
     * desistir (DB_READ_TIMEOUT) depois que ele gravou, o Laravel não repete o
     * insert, que daria esse 422 para o cliente que acabou de ser criado.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Client::class);

        // O domínio barberflow.invalid é reservado para o e-mail de quem foi excluído.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|not_regex:/@barberflow\.invalid$/i|unique:clients,email',
            'phone' => 'nullable|string|max:20',
        ]);

        $client = EmailTakenException::guard(fn () => DB::transaction(fn () => Client::create($validated)));

        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        Gate::authorize('view', $client);

        return $client;
    }

    public function update(Request $request, Client $client)
    {
        Gate::authorize('update', $client);

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|not_regex:/@barberflow\.invalid$/i|unique:clients,email,'.$client->id,
            'phone' => 'nullable|string|max:20',
        ];

        // O cliente edita só nome e telefone: o e-mail do cadastro fica com a barbearia. O missing recusa
        // o campo com qualquer valor; o prohibited deixava passar vazio e null, que chegavam ao banco (erro 500).
        if (Gate::denies('changeEmail', $client)) {
            $rules['email'] = 'missing';
        }

        $validated = $request->validate($rules);

        // Outro cadastro gravou o mesmo e-mail depois da validação: o mesmo 422 da regra unique.
        EmailTakenException::guard(fn () => $client->update($validated));

        return $client;
    }

    /**
     * Exclusão lógica com anonimização (LGPD): a linha fica para o histórico,
     * mas sem nome, e-mail e telefone do cliente. A conta do cliente, se existir,
     * é apagada com os tokens e as sessões. Com agendamento de hoje em diante
     * ainda ativo, 409 (cancele ou remarque antes).
     */
    public function destroy(Client $client)
    {
        Gate::authorize('delete', $client);

        DB::transaction(function () use ($client) {
            $client->ensureNoUpcomingAppointments();
            $client->anonymizeAndDelete();
        });

        return response()->noContent();
    }
}
