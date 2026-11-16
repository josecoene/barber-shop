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
 * - O cadastro já está ligado a esta conta (a confirmação chegou duas vezes ao
 *   mesmo tempo, com dois cliques no link): não faz nada.
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

    /**
     * Quantas vezes a transação da ligação roda quando o banco a desfaz por
     * concorrência. Duas contas que confirmam o e-mail no mesmo instante, com
     * e-mails que caem no mesmo intervalo do índice unique de clients.email,
     * travam esse intervalo na leitura com trava (sem a linha, o MySQL trava o
     * intervalo: gap lock) e esperam uma pela outra no insert. O MySQL desfaz
     * uma delas (deadlock, erro 1213), e o Laravel roda a transação dela de
     * novo: a outra já gravou, e a leitura e o insert passam. Sem repetir, a
     * resposta era 500, e a conta ficava com o e-mail confirmado e sem cadastro
     * de cliente, sem poder agendar, porque a confirmação não roda outra vez.
     * O Laravel só repete a transação de fora, e a da confirmação é a de fora
     * (o pedido do link não abre outra).
     */
    public const ATTEMPTS = 3;

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

            // A mesma conta confirmou duas vezes ao mesmo tempo (dois cliques no link, ou duas abas): o outro pedido
            // já ligou a conta a este cadastro. Não há nada a fazer, e o aviso de "outra conta" seria falso.
            if ((int) $client->user_id === (int) $user->getKey()) {
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
        }, self::ATTEMPTS);
    }
}
