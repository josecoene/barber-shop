<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /: leva cada perfil à tela inicial dele. Admin e barbeiro vão para a agenda;
 * o cliente, para a conta dele.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // A mensagem que chegou para a tela inicial (ex.: "E-mail confirmado.") vale para uma
        // requisição só; o reflash a guarda por mais uma, para ela aparecer na tela do perfil.
        $request->session()->reflash();

        return $request->user()->isClient()
            ? redirect()->route('account.show')
            : redirect()->route('appointments.index');
    }
}
