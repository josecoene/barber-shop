<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Exceptions\EmailTakenException;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Cadastro de conta de cliente, pelas telas (/register) e pela API (/api/register),
 * e o reenvio do link de confirmação do e-mail.
 *
 * A conta nasce com o perfil client e o e-mail ainda não confirmado. Ela só é
 * ligada a um cadastro de cliente (tabela clients) quando o dono confirma o
 * e-mail (App\Listeners\LinkClientToVerifiedUser). Assim ninguém vê o histórico
 * de outra pessoa só por digitar o e-mail dela no cadastro.
 *
 * Com o servidor de e-mail fora do ar, o cadastro cria a conta do mesmo jeito e
 * quem chama avisa que o link não saiu (VERIFICATION_NOT_SENT); o reenvio
 * responde que não conseguiu (RESEND_FAILED: 503 na API e aviso nas telas). Nos
 * dois, a falha vai para o log sem o endereço (report() e bootstrap/app.php), e
 * o cliente pede outro link depois. Um erro 500 no cadastro faria a pessoa achar
 * que a conta não existe, e a segunda tentativa daria "e-mail já cadastrado".
 */
class ClientRegistration
{
    /**
     * Aviso do cadastro feito sem o e-mail de confirmação.
     */
    public const VERIFICATION_NOT_SENT = 'Conta criada, mas não foi possível enviar o e-mail de confirmação agora. Peça um novo link em alguns minutos.';

    /**
     * Recusa do reenvio do link de confirmação quando o e-mail não sai.
     */
    public const RESEND_FAILED = 'Não foi possível enviar o link de confirmação agora. Tente de novo em alguns minutos.';

    /**
     * Regras de validação do cadastro. O domínio barberflow.invalid é reservado
     * para o e-mail de cadastros excluídos e nunca recebe mensagem.
     *
     * O e-mail não aceita acento nem outro caractere fora do ASCII (regra ascii).
     * O MySQL compara sem diferenciar acento, e maria@gmáil.com (o domínio
     * xn--gmil-6na.com, que qualquer um pode registrar) seria, para o banco, o
     * mesmo e-mail de maria@gmail.com: a conta com o endereço parecido tomaria o
     * lugar da conta da dona do e-mail de verdade, que receberia "Este e-mail já
     * está cadastrado.", e o "esqueci minha senha" mandaria o link para o
     * endereço parecido. A confirmação também nunca liga a conta a um cadastro
     * de cliente com outro e-mail (App\Listeners\LinkClientToVerifiedUser).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'ascii', 'max:255', 'not_regex:/@barberflow\.invalid$/i', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Cria a conta e dispara o Registered. O Laravel responde a esse evento
     * mandando o e-mail de confirmação, porque o User implementa MustVerifyEmail.
     * Devolve a conta e se o e-mail de confirmação saiu.
     *
     * O e-mail repetido que passou pela regra unique (outro cadastro com o mesmo
     * e-mail no mesmo instante) sai como o mesmo 422 dela, e a conta não é criada.
     * O insert vai dentro de uma transação: se a espera pela resposta do banco
     * desistir (DB_READ_TIMEOUT) depois que ele gravou, o Laravel não repete o
     * insert, que daria esse 422 para a conta que acabou de ser criada, sem o
     * e-mail de confirmação.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     * @return array{0: User, 1: bool}
     *
     * @throws EmailTakenException
     */
    public function register(array $data): array
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);

        // O perfil não vem da requisição: é sempre client.
        EmailTakenException::guard(fn () => DB::transaction(fn () => $user->forceFill(['role' => UserRole::Client])->save()));

        return [$user, $this->deliver(fn () => event(new Registered($user)))];
    }

    /**
     * Manda um link de confirmação novo. false quando o e-mail não saiu.
     */
    public function resendVerification(User $user): bool
    {
        return $this->deliver(fn () => $user->sendEmailVerificationNotification());
    }

    /**
     * Roda o envio e diz se ele deu certo. A falha do servidor de e-mail vai para
     * o log pelo report(), sem o endereço (bootstrap/app.php). Os outros erros
     * (um template quebrado, por exemplo) continuam sendo erro.
     */
    private function deliver(Closure $send): bool
    {
        try {
            $send();
        } catch (TransportExceptionInterface $e) {
            report($e);

            return false;
        }

        return true;
    }
}
