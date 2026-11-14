<?php

namespace App\Models;

use App\Models\Concerns\HasAppointments;
use App\Models\Concerns\ReleasesEmailOnDelete;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasAppointments, HasFactory, ReleasesEmailOnDelete, SoftDeletes;

    /**
     * Recusa (422 em notes) de observação nova no agendamento ou no pagamento de
     * um cliente excluído: a exclusão apagou as observações dele (ver
     * anonymizeAndDelete()), e um texto escrito depois voltaria a ligar dado
     * pessoal ao cadastro, sem nada que o apagasse. Quem confere é o
     * App\Services\AppointmentScheduler (remarcar) e o
     * App\Services\PaymentRecorder (registrar e corrigir), depois das travas.
     */
    public const NOTES_AFTER_DELETION = 'O cliente foi excluído: deixe as observações em branco.';

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];

    /**
     * A conta não sai no JSON: nem o id dela, nem a relação user, mesmo quando
     * algum código a deixa carregada. has_login diz se o cliente tem conta.
     *
     * @var list<string>
     */
    protected $hidden = ['user_id', 'user'];

    /**
     * @var list<string>
     */
    protected $appends = ['has_login'];

    /**
     * Conta (usuário com perfil client) ligada a este cadastro. A ligação é feita
     * quando o dono da conta confirma o e-mail (App\Listeners\LinkClientToVerifiedUser).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * true quando o cliente tem conta. Olha só a coluna user_id, sem consultar
     * a tabela users.
     *
     * @return Attribute<bool, never>
     */
    protected function hasLogin(): Attribute
    {
        return Attribute::get(fn (): bool => $this->user_id !== null);
    }

    /**
     * Exclui o cliente sem apagar a linha (soft delete), para o histórico de
     * atendimentos continuar apontando para ela, e apaga os dados pessoais (LGPD).
     * O e-mail vira um endereço que nunca recebe mensagem (.invalid) e libera o
     * e-mail original para um cadastro novo. As observações dos agendamentos
     * dele e dos pagamentos desses agendamentos também são apagadas: são texto
     * livre e podem ter dados da pessoa (telefone, endereço, saúde).
     *
     * Depois da exclusão, observação nova nesses agendamentos e pagamentos é
     * recusada (NOTES_AFTER_DELETION). No MySQL, o update das observações dos
     * agendamentos trava as linhas deles, e o dos pagamentos, que vem depois,
     * trava as linhas dos pagamentos. O registro de pagamento trava a linha do
     * agendamento, e a correção a do pagamento (App\Services\PaymentRecorder):
     * um registro ou uma correção feitos ao mesmo tempo ou terminam antes (e
     * têm as observações apagadas aqui) ou esperam a exclusão terminar e, como
     * conferem o cliente depois da trava, recusam as observações.
     *
     * Se o cliente tinha conta, o usuário é apagado junto, com os tokens e as
     * sessões dele.
     */
    public function anonymizeAndDelete(): void
    {
        DB::transaction(function () {
            $user = $this->user;

            $this->user()->dissociate();

            $this->update([
                'name' => 'Cliente removido',
                'email' => $this->releasedEmail(),
                'phone' => null,
            ]);

            $this->appointments()->whereNotNull('notes')->update(['notes' => null]);

            // Pelos ids, para o MySQL procurar os pagamentos pelo índice de appointment_id.
            Payment::query()
                ->whereIn('appointment_id', $this->appointments()->pluck('id'))
                ->whereNotNull('notes')
                ->update(['notes' => null]);

            $user?->deleteWithLogins();

            $this->delete();
        });
    }
}
