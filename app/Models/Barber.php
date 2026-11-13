<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\ReleasesEmailOnDelete;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Barber extends Model
{
    use HasFactory, ReleasesEmailOnDelete, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];

    /**
     * O login não sai no JSON: nem o id dele, nem a relação user, que o syncLogin
     * deixa carregada (sem ela escondida, o store e o update devolveriam o login
     * inteiro, com id e perfil). has_login diz se o barbeiro tem login.
     *
     * @var list<string>
     */
    protected $hidden = ['user_id', 'user'];

    /**
     * @var list<string>
     */
    protected $appends = ['has_login'];

    /**
     * Login (usuário com perfil barber) deste barbeiro, quando o admin criou um.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * true quando o barbeiro tem login. Olha só a coluna user_id, sem consultar
     * a tabela users.
     *
     * @return Attribute<bool, never>
     */
    protected function hasLogin(): Attribute
    {
        return Attribute::get(fn (): bool => $this->user_id !== null);
    }

    /**
     * Cria ou atualiza o login do barbeiro. O nome e o e-mail do login ficam
     * sempre iguais aos do cadastro. Com senha, cria o login se ele não existir
     * ou troca a senha do que já existe; a troca encerra os logins abertos
     * (tokens e sessões), para quem tinha a senha antiga sair. Sem senha e sem
     * login, não faz nada.
     *
     * O login nasce com o e-mail confirmado: quem cria é o admin.
     */
    public function syncLogin(?string $password = null): void
    {
        $user = $this->user;

        if ($user === null && $password === null) {
            return;
        }

        DB::transaction(function () use ($user, $password) {
            $isNew = $user === null;
            $user ??= new User;

            $user->forceFill([
                'name' => $this->name,
                'email' => $this->email,
                'role' => UserRole::Barber,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();

            if ($isNew) {
                $this->user()->associate($user)->save();
            } elseif ($password !== null) {
                $user->revokeLogins();
            }
        });
    }

    /**
     * Exclui o barbeiro sem apagar a linha (soft delete). Só o nome fica, para o
     * histórico de atendimentos mostrar quem atendeu. O e-mail é trocado por um
     * endereço que nunca recebe mensagem (.invalid), o que libera o original no
     * unique do banco para um cadastro novo, e o telefone, que não tem mais uso
     * depois da exclusão, é apagado (LGPD).
     *
     * Se o barbeiro tinha login, o usuário é apagado junto, com os tokens e as
     * sessões dele: quem foi excluído não entra mais.
     */
    public function releaseEmailAndDelete(): void
    {
        DB::transaction(function () {
            $user = $this->user;

            $this->user()->dissociate();

            $this->update([
                'email' => $this->releasedEmail(),
                'phone' => null,
            ]);

            $user?->deleteWithLogins();

            $this->delete();
        });
    }
}
