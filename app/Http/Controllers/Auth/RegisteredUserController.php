<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ClientRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Cadastro de conta de cliente pelas telas. A versão da API fica no AuthController.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Cria a conta, já deixa o cliente logado e leva ao aviso de "confirme seu
     * e-mail": as telas do cliente só abrem depois da confirmação.
     */
    public function store(Request $request, ClientRegistration $registration): RedirectResponse
    {
        $validated = $request->validate(ClientRegistration::rules());

        $user = $registration->register($validated);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
