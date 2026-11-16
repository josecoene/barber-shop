<?php

namespace App\Http\Controllers;

use App\Models\Barber;
use App\Rules\NotOnlySpaces;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Permissões na BarberPolicy: só o admin cria, edita e exclui; o barbeiro vê; o
 * cliente vê só o id e o nome.
 *
 * O campo password (opcional, só o admin manda) cria o login do barbeiro ou
 * redefine a senha dele.
 */
class BarberController extends Controller
{
    /**
     * Lista paginada e em ordem de nome. Filtros opcionais: ?search= (nome ou
     * e-mail; para o cliente, só nome), ?per_page= (1 a 100, padrão 15) e
     * ?page= (1 a 100000).
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Barber::class);

        // O teto do page evita que a conta do offset ((page - 1) * per_page) estoure o inteiro e vire erro 500.
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $search = $filters['search'] ?? null;

        // Quem não vê o e-mail também não busca por ele: o resultado da busca entregaria o e-mail aos poucos.
        $seesContact = Gate::allows('viewContactDetails', Barber::class);

        $barbers = Barber::query()
            ->when($search !== null, function ($query) use ($search, $seesContact) {
                $query->where(function ($query) use ($search, $seesContact) {
                    $query->where('name', 'like', "%{$search}%")
                        ->when($seesContact, fn ($query) => $query->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        if (! $seesContact) {
            $barbers->through(fn (Barber $barber) => $this->withoutContactDetails($barber));
        }

        // JsonResource::collection() devolve a página como data, links e meta, sem precisar de classe própria.
        return JsonResource::collection($barbers);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Barber::class);

        // O domínio barberflow.invalid é reservado para o e-mail de quem foi excluído.
        // O e-mail do barbeiro é o login dele, então também não pode ser o de outro usuário.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', 'not_regex:/@barberflow\.invalid$/i', 'unique:barbers,email', 'unique:users,email'],
            'phone' => 'nullable|string|max:20',
            'password' => $this->passwordRules(),
        ]);

        $barber = DB::transaction(function () use ($validated) {
            $barber = Barber::create(Arr::except($validated, 'password'));

            $barber->syncLogin($validated['password'] ?? null);

            return $barber;
        });

        return response()->json($barber, 201);
    }

    public function show(Barber $barber)
    {
        Gate::authorize('view', $barber);

        return Gate::allows('viewContactDetails', Barber::class)
            ? $barber
            : $this->withoutContactDetails($barber);
    }

    /**
     * Mudou nome ou e-mail, o login acompanha. Com password, o login é criado (se
     * não existia) ou ganha a senha nova.
     */
    public function update(Request $request, Barber $barber)
    {
        Gate::authorize('update', $barber);

        // Senha nova sem e-mail no pedido: o login vai usar o e-mail que está no cadastro,
        // então ele entra na validação para o unique de users conferir se está livre. Qualquer
        // senha enviada conta: o filled() apara o texto e deixava de fora a senha só de espaços.
        if ($request->input('password') !== null && ! $request->has('email')) {
            $request->merge(['email' => $barber->email]);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255', 'not_regex:/@barberflow\.invalid$/i',
                Rule::unique('barbers', 'email')->ignore($barber->id),
                Rule::unique('users', 'email')->ignore($barber->user_id),
            ],
            'phone' => 'nullable|string|max:20',
            'password' => $this->passwordRules(),
        ]);

        DB::transaction(function () use ($barber, $validated) {
            $barber->update(Arr::except($validated, 'password'));

            $barber->syncLogin($validated['password'] ?? null);
        });

        return $barber;
    }

    /**
     * Exclusão lógica: mantém o nome para o histórico, troca o e-mail (liberando
     * o original para um cadastro novo) e apaga o telefone. O login do barbeiro,
     * se existir, é apagado com os tokens e as sessões. Com agendamento de hoje
     * em diante ainda ativo, 409 (cancele ou remarque antes).
     */
    public function destroy(Barber $barber)
    {
        Gate::authorize('delete', $barber);

        DB::transaction(function () use ($barber) {
            $barber->ensureNoUpcomingAppointments();
            $barber->releaseEmailAndDelete();
        });

        return response()->noContent();
    }

    /**
     * Senha do login do barbeiro, opcional. O NotOnlySpaces recusa a senha só de espaços:
     * o Laravel não apara senha e trata esse texto como vazio, sem rodar o mínimo de 8
     * caracteres nele. O cadastro de conta e a troca de senha usam o required, que já recusa.
     *
     * @return array<int, mixed>
     */
    private function passwordRules(): array
    {
        return ['nullable', 'string', new NotOnlySpaces, Password::defaults()];
    }

    /**
     * Deixa no JSON só o id e o nome (o que o cliente pode ver).
     */
    private function withoutContactDetails(Barber $barber): Barber
    {
        return $barber->setVisible(['id', 'name']);
    }
}
