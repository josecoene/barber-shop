<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmação de e-mail pelas telas: o aviso, o link que chega por e-mail e o
 * reenvio do link. O reenvio pela API fica no AuthController.
 */
class EmailVerificationController extends Controller
{
    /**
     * Aviso de "confirme seu e-mail". Quem já confirmou vai para a tela inicial.
     */
    public function notice(Request $request): RedirectResponse|View
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->intended(route('home'))
            : view('auth.verify-email');
    }

    /**
     * Link do e-mail (rota assinada). O EmailVerificationRequest confere se o
     * link é do usuário logado; o fulfill() marca o e-mail como confirmado e
     * dispara o Verified, que liga a conta ao cadastro de cliente.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('home'))->with('status', 'E-mail confirmado.');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Enviamos um novo link de confirmação para o seu e-mail.');
    }
}
