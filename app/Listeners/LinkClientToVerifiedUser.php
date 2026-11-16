<?php

namespace App\Listeners;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quando um cliente confirma o e-mail, liga a conta dele a um cadastro de
 * cliente. O Laravel registra este listener sozinho (descoberta de eventos em
 * app/Listeners), pelo tipo do parâmetro do handle().
 *
 * - Existe cadastro com o mesmo e-mail, não excluído e sem conta: liga a ele, e o
 *   cliente passa a ver o histórico que a barbearia já tinha. O telefone do
 *   cadastro da conta só entra se o da barbearia estiver vazio.
 * - Não existe: cria um cadastro novo com nome, e-mail e telefone da conta.
 * - O cadastro já tem outra conta: não liga e registra no log, só com os ids.
 *
 * A ligação só acontece depois da confirmação porque é ela que prova que a
 * pessoa é dona do e-mail.
 */
class LinkClientToVerifiedUser
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->isClient() || $user->client()->withTrashed()->exists()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $client = Client::query()->where('email', $user->email)->lockForUpdate()->first();

            if ($client === null) {
                $client = new Client([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
                $client->user()->associate($user);
                $client->save();

                return;
            }

            if ($client->user_id !== null) {
                Log::warning('Conta de cliente confirmada sem cadastro: o cadastro com o mesmo e-mail já tem outra conta.', [
                    'user_id' => $user->getKey(),
                    'client_id' => $client->getKey(),
                ]);

                return;
            }

            $client->user()->associate($user);
            $client->phone ??= $user->phone;
            $client->save();
        });
    }
}
