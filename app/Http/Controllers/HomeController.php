<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /: leva cada perfil à tela inicial dele. O admin vai para o dashboard; o
 * barbeiro, para a agenda; o cliente, para Meus horários.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // A mensagem que chegou para a tela inicial (ex.: "E-mail confirmado.") vale para uma
        // requisição só; o reflash a guarda por mais uma, para ela aparecer na tela do perfil.
        $request->session()->reflash();

        $user = $request->user();

        return redirect()->route(match (true) {
            $user->isAdmin() => 'dashboard',
            $user->isClient() => 'my-appointments.index',
            default => 'appointments.index',
        });
    }
}
