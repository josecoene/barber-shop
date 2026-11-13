<?php

namespace App\Models;

use App\Models\Concerns\ReleasesEmailOnDelete;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, ReleasesEmailOnDelete, SoftDeletes;

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
     * e-mail original para um cadastro novo.
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

            $user?->deleteWithLogins();

            $this->delete();
        });
    }
}
