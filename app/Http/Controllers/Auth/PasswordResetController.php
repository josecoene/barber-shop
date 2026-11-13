<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Esqueci minha senha" pelas telas: pedir o link e redefinir a senha com ele.
 * A versão da API fica no AuthController.
 */
class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, PasswordResetter $resetter): RedirectResponse
    {
        $validated = $request->validate(PasswordResetter::linkRules());

        return back()->with('status', $resetter->sendLink($validated['email']));
    }

    /**
     * Tela aberta pelo link do e-mail (/reset-password/{token}?email=...).
     */
    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request, PasswordResetter $resetter): RedirectResponse
    {
        $validated = $request->validate(PasswordResetter::resetRules());

        return redirect()->route('login')->with('status', $resetter->reset($validated));
    }
}
