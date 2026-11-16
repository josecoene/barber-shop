<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * Quem entra no sistema. O perfil (role) não está no Fillable de propósito: ele
 * só é gravado com forceFill pelo código (cadastro de cliente, login de
 * barbeiro, seeders), nunca a partir do que chega na requisição.
 *
 * MustVerifyEmail: o cliente que cria a conta confirma o e-mail antes de usar as
 * rotas com o middleware verified. Admin e barbeiro, criados pelo sistema, já
 * nascem com email_verified_at preenchido.
 */
#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Cadastro de barbeiro ligado a este login (só para o perfil barber).
     *
     * @return HasOne<Barber, $this>
     */
    public function barber(): HasOne
    {
        return $this->hasOne(Barber::class);
    }

    /**
     * Cadastro de cliente ligado a esta conta (só para o perfil client). Nasce,
     * ou é ligado, quando o cliente confirma o e-mail.
     *
     * @return HasOne<Client, $this>
     */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBarber(): bool
    {
        return $this->role === UserRole::Barber;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * Quem pode entrar, pelas telas ou pela API:
     * - admin;
     * - barbeiro com cadastro ativo (não excluído);
     * - cliente cujo cadastro não foi excluído. O cliente que acabou de criar a
     *   conta ainda não tem cadastro (ele nasce na confirmação do e-mail) e entra
     *   para poder confirmar.
     *
     * Usuário sem perfil não entra.
     */
    public function canLogIn(): bool
    {
        return match ($this->role) {
            UserRole::Admin => true,
            UserRole::Barber => $this->barber()->exists(),
            UserRole::Client => ! $this->client()->onlyTrashed()->exists(),
            null => false,
        };
    }

    /**
     * Encerra os logins abertos: apaga os tokens da API e as sessões das telas.
     * As sessões só ficam na tabela com SESSION_DRIVER=database; com outro
     * driver, a sessão de um usuário apagado deixa de achar o usuário e vira
     * visitante.
     */
    public function revokeLogins(): void
    {
        $this->tokens()->delete();

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->delete();
    }

    /**
     * Apaga o usuário depois de encerrar os logins dele (tokens e sessões).
     */
    public function deleteWithLogins(): void
    {
        DB::transaction(function () {
            $this->revokeLogins();
            $this->delete();
        });
    }
}
