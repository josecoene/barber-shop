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
    /**
     * Aviso do cadastro feito com o e-mail de confirmação enviado.
     */
    public const VERIFICATION_SENT = 'Enviamos um link de confirmação para o seu e-mail.';

    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Cria a conta, já deixa o cliente logado e leva ao aviso de "confirme seu
     * e-mail": as telas do cliente só abrem depois da confirmação. O aviso diz
     * se o link saiu, só nessa primeira vez: o texto fixo da tela não diz, porque
     * ela abre de novo depois. Com o servidor de e-mail fora do ar, a conta é
     * criada do mesmo jeito, e o aviso diz que o link não saiu e que dá para
     * pedir outro na mesma tela.
     */
    public function store(Request $request, ClientRegistration $registration): RedirectResponse
    {
        $validated = $request->validate(ClientRegistration::rules());

        [$user, $verificationSent] = $registration->register($validated);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        $redirect = redirect()->route('verification.notice');

        return $verificationSent
            ? $redirect->with('status', self::VERIFICATION_SENT)
            : $redirect->with('warning', ClientRegistration::VERIFICATION_NOT_SENT);
    }
}
