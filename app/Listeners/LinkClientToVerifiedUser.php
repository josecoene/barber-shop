<?php

namespace App\Listeners;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Quando um cliente confirma o e-mail, liga a conta dele a um cadastro de
 * cliente. O Laravel registra este listener sozinho (descoberta de eventos em
 * app/Listeners), pelo tipo do parâmetro do handle().
 *
 * - Existe cadastro com o mesmo e-mail (maiúsculas e minúsculas não contam), não
 *   excluído e sem conta: liga a ele, e o cliente passa a ver o histórico que a
 *   barbearia já tinha. O telefone do cadastro da conta só entra se o da
 *   barbearia estiver vazio.
 * - Não existe: cria um cadastro novo com nome, e-mail e telefone da conta.
 * - O banco achou um cadastro com um e-mail parecido, mas que não é o mesmo
 *   (veja SIMILAR_EMAIL): não liga, não cria outro e registra no log, só com
 *   os ids.
 * - O cadastro já tem outra conta: não liga e registra no log, só com os ids.
 *
 * A ligação só acontece depois da confirmação porque é ela que prova que a
 * pessoa é dona do e-mail.
 */
class LinkClientToVerifiedUser
{
    /**
     * Aviso no log quando a busca acha um cadastro com outro e-mail. O MySQL
     * (collation utf8mb4_unicode_ci) compara sem diferenciar acento: a busca
     * por maria@gmáil.com acha o cadastro maria@gmail.com. Só que gmáil.com é
     * outro domínio (xn--gmil-6na.com), que qualquer um pode registrar, e quem
     * confirmou provou ser dono de outro endereço: a ligação daria a ele o
     * histórico de outra pessoa. Por isso a ligação pede o mesmo e-mail, e a
     * única diferença aceita é entre maiúsculas e minúsculas, que não mudam o
     * destino ("Maria@Gmail.com" e "maria@gmail.com" chegam à mesma pessoa).
     * Um cadastro novo também não nasce, porque o índice unique do banco
     * compara do mesmo jeito e recusaria o e-mail. No SQLite local a busca
     * compara letra por letra, e esse caso não acontece.
     */
    public const SIMILAR_EMAIL = 'Conta de cliente confirmada sem cadastro: o banco achou um cadastro com um e-mail parecido, mas não o mesmo.';

    /**
     * Aviso no log quando o cadastro com o mesmo e-mail já tem outra conta.
     */
    public const TAKEN_BY_ANOTHER_ACCOUNT = 'Conta de cliente confirmada sem cadastro: o cadastro com o mesmo e-mail já tem outra conta.';

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

            $warning = match (true) {
                Str::lower($client->email) !== Str::lower($user->email) => self::SIMILAR_EMAIL,
                $client->user_id !== null => self::TAKEN_BY_ANOTHER_ACCOUNT,
                default => null,
            };

            if ($warning !== null) {
                Log::warning($warning, [
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
