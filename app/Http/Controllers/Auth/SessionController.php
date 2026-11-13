<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Login e logout das telas, por sessão (cookie + CSRF). A API usa token Bearer
 * (AuthController).
 */
class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Confere o login (LoginAttempt: limite de tentativas e recusas) e só então
     * abre a sessão, com "lembrar de mim" quando o campo remember vier marcado.
     * Volta para a página que o visitante tentou abrir ou para a tela inicial
     * do perfil (rota home).
     */
    public function store(Request $request, LoginAttempt $loginAttempt): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = $loginAttempt->authenticate($request, $validated['email'], $validated['password']);

        Auth::guard('web')->login($user, $request->boolean('remember'));

        // Id de sessão novo no login: um id conhecido antes do login não vale mais (session fixation).
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
