<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\ClientRegistration;
use App\Services\Auth\LoginAttempt;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticação da API. Postman e clientes externos usam token Bearer (POST
 * /api/login). As telas entram por sessão (SessionController) e chamam a API com
 * o cookie da sessão; o Sanctum reconhece as telas pelo Referer (statefulApi).
 */
class AuthController extends Controller
{
    /**
     * Devolve um token que vale config('sanctum.expiration') minutos. O limite de
     * tentativas e as recusas são os mesmos do login das telas (LoginAttempt).
     */
    public function login(Request $request, LoginAttempt $loginAttempt): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $loginAttempt->authenticate($request, $validated['email'], $validated['password']);

        // O expires_at grava no token a mesma validade que o Sanctum confere, para o cliente saber quando renovar.
        $minutes = config('sanctum.expiration');
        $token = $user->createToken(
            $validated['device_name'] ?? 'api',
            ['*'],
            $minutes ? now()->addMinutes($minutes) : null,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toJSON(),
            'user' => $this->summary($user),
        ]);
    }

    /**
     * Com token, apaga o token usado na chamada. Vindo das telas (sessão), faz o
     * logout da sessão.
     */
    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->noContent();
    }

    /**
     * Usuário logado, com o perfil e o id do cadastro ligado: barber_id para o
     * barbeiro e client_id para o cliente (null quando não há).
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            ...$this->summary($user),
            'email_verified_at' => $user->email_verified_at?->toJSON(),
            'barber_id' => $user->barber()->value('id'),
            'client_id' => $user->client()->value('id'),
        ]);
    }

    /**
     * Troca a senha de quem está logado, conferindo a senha atual. Os outros
     * tokens do usuário param de valer; o desta chamada continua. As outras
     * sessões das telas caem na próxima requisição delas (auth.session e Sanctum
     * conferem o resumo da senha guardado na sessão); a sessão desta chamada
     * guarda o resumo novo e continua.
     */
    public function updatePassword(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->password = $validated['password'];
        $user->save();

        $current = $user->currentAccessToken();

        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        return response()->noContent();
    }

    /**
     * Cadastro de conta de cliente. A conta nasce sem o e-mail confirmado; o
     * link de confirmação vai por e-mail.
     */
    public function register(Request $request, ClientRegistration $registration): JsonResponse
    {
        $validated = $request->validate(ClientRegistration::rules());

        $registration->register($validated);

        return response()->json([
            'message' => 'Conta criada. Confirme seu e-mail pelo link que enviamos para começar a usar a conta.',
        ], 201);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Seu e-mail já está confirmado.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Enviamos um novo link de confirmação para o seu e-mail.'], 202);
    }

    public function forgotPassword(Request $request, PasswordResetter $resetter): JsonResponse
    {
        $validated = $request->validate(PasswordResetter::linkRules());

        return response()->json(['message' => $resetter->sendLink($validated['email'])]);
    }

    public function resetPassword(Request $request, PasswordResetter $resetter): JsonResponse
    {
        $validated = $request->validate(PasswordResetter::resetRules());

        return response()->json(['message' => $resetter->reset($validated)]);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string|null}
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
        ];
    }
}
