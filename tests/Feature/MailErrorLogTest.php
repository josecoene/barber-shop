<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * Falha no envio de e-mail no log sem o endereço (bootstrap/app.php). A
 * mensagem do erro do Symfony Mailer traz a resposta do servidor de e-mail,
 * que costuma repetir o endereço de quem ia receber, e o log padrão do Laravel
 * gravaria essa mensagem inteira; o projeto grava só a classe do erro, o código
 * e o arquivo:linha de quem mandou o e-mail. O failMailDelivery() do
 * tests/TestCase.php faz o envio falhar com o endereço no texto do erro, e o
 * Log::spy() recebe também o log padrão do Laravel, então o once() confere que
 * ele não gravou nada. O lembrete por e-mail trata a própria falha (ReminderCommandTest
 * e ReminderApiTest), e o erro de banco fica no DatabaseErrorLogTest.
 *
 * Com o e-mail fora do ar, nenhum desses pedidos dá erro 500: o "esqueci a
 * senha" responde o mesmo de sempre, o cadastro de conta cria a conta e avisa
 * que o link não saiu, e o reenvio do link responde 503 na API e volta à tela
 * com o aviso (ClientRegistration).
 */
class MailErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Falha no envio de e-mail.';

    private const ADDRESS = 'maria.sigilosa@barberflow.test';

    private const SENT = 'Se o e-mail estiver cadastrado, você vai receber o link para redefinir a senha.';

    private const NOT_SENT = 'Conta criada, mas não foi possível enviar o e-mail de confirmação agora. Peça um novo link em alguns minutos.';

    private const RESEND_FAILED = 'Não foi possível enviar o link de confirmação agora. Tente de novo em alguns minutos.';

    public function test_logs_the_mail_error_without_the_address(): void
    {
        Log::spy();

        // A resposta de um servidor SMTP que recusa o destinatário, no formato do Postfix.
        $line = __LINE__ + 1;
        report(new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <'.self::ADDRESS.'>: Recipient address rejected".', 550));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && $context === [
                'class' => UnexpectedResponseException::class,
                'code' => 550,
                'file' => 'tests/Feature/MailErrorLogTest.php:'.$line,
            ]);
    }

    public function test_a_mail_error_inside_another_error_is_logged_the_same_way(): void
    {
        Log::spy();

        $mailError = new TransportException('Conexão recusada ao enviar para '.self::ADDRESS.'.');
        report(new RuntimeException('Falhou: '.$mailError->getMessage(), 0, $mailError));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && $context['class'] === TransportException::class
            && ! str_contains((string) json_encode($context), self::ADDRESS));
    }

    public function test_forgot_password_answers_the_same_when_the_email_fails(): void
    {
        $user = $this->makeClientUser();
        $this->failMailDelivery();
        Log::spy();

        // Sem isso, a falha no envio, que só acontece com o e-mail cadastrado, diria quem tem conta.
        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['message' => self::SENT]);

        $this->postJson('/api/forgot-password', ['email' => 'ninguem@barberflow.test'])
            ->assertOk()
            ->assertExactJson(['message' => self::SENT]);

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Services/Auth/PasswordResetter.php:');
    }

    public function test_forgot_password_screen_answers_the_same_when_the_email_fails(): void
    {
        $user = $this->makeClientUser();
        $this->failMailDelivery();
        Log::spy();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::SENT);

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Services/Auth/PasswordResetter.php:');
    }

    /**
     * A conta existe mesmo sem o e-mail de confirmação: um erro 500 faria a
     * pessoa achar que o cadastro falhou, e a segunda tentativa daria "Este
     * e-mail já está cadastrado.". A resposta é a do cadastro feito (201), com o
     * aviso de que o link não saiu e de que dá para pedir outro.
     */
    public function test_registration_with_the_email_down_creates_the_account_and_warns(): void
    {
        $this->failMailDelivery();
        Log::spy();

        $this->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertExactJson(['message' => self::NOT_SENT]);

        $user = User::firstWhere('email', self::ADDRESS);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertFalse($user->hasVerifiedEmail());

        $this->assertMailErrorLoggedWithoutTheAddress(self::ADDRESS, 'app/Services/Auth/ClientRegistration.php:');
    }

    public function test_screen_registration_with_the_email_down_logs_in_and_warns(): void
    {
        $this->withoutVite();
        $this->failMailDelivery();
        Log::spy();

        $this->post('/register', $this->registration())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/email/verify')
            ->assertSessionHas('warning', self::NOT_SENT)
            ->assertSessionMissing('status');

        $this->assertAuthenticatedAs(User::firstWhere('email', self::ADDRESS), 'web');
        $this->assertMailErrorLoggedWithoutTheAddress(self::ADDRESS, 'app/Services/Auth/ClientRegistration.php:');

        // A tela do aviso não diz que o link foi enviado, e o botão de reenviar continua lá.
        $this->get('/email/verify')
            ->assertOk()
            ->assertSeeText(self::NOT_SENT)
            ->assertSeeText('Falta confirmar o e-mail')
            ->assertDontSeeText('Enviamos')
            ->assertDontSeeText('Confira a caixa de spam')
            ->assertSeeText('Reenviar o link');

        $this->assertReloadedNoticeDoesNotSayTheLinkWasSent();
    }

    public function test_resending_the_confirmation_with_the_email_down_returns_503(): void
    {
        $user = Sanctum::actingAs($this->makeClientUser(verified: false));
        $this->failMailDelivery();
        Log::spy();

        $this->postJson('/api/email/verification-notification')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', self::RESEND_FAILED);

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Services/Auth/ClientRegistration.php:');
    }

    public function test_resending_the_confirmation_on_the_screen_with_the_email_down_goes_back_with_the_warning(): void
    {
        $this->withoutVite();
        $user = $this->makeClientUser(verified: false);
        $this->failMailDelivery();
        Log::spy();

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertRedirect('/email/verify')
            ->assertSessionHas('warning', self::RESEND_FAILED)
            ->assertSessionMissing('status');

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Services/Auth/ClientRegistration.php:');

        $this->get('/email/verify')
            ->assertOk()
            ->assertSeeText(self::RESEND_FAILED)
            ->assertDontSeeText('Enviamos');

        $this->assertReloadedNoticeDoesNotSayTheLinkWasSent();
    }

    /**
     * Recarregada (ou aberta de novo depois de entrar), a tela já não tem o aviso
     * amarelo, que vale para uma vez só, e mesmo assim não diz que o link foi
     * enviado: a pessoa não fica esperando um e-mail que não saiu.
     */
    private function assertReloadedNoticeDoesNotSayTheLinkWasSent(): void
    {
        $this->get('/email/verify')
            ->assertOk()
            ->assertDontSee('alert-warning')
            ->assertDontSeeText('Enviamos')
            ->assertSeeText('Falta confirmar o e-mail')
            ->assertSeeText('Reenviar o link');
    }

    /**
     * Cadastro de conta válido, com o endereço que o erro do envio repete.
     *
     * @return array<string, string>
     */
    private function registration(): array
    {
        return [
            'name' => 'Maria Sigilosa',
            'email' => self::ADDRESS,
            'password' => 'senha-da-cliente',
            'password_confirmation' => 'senha-da-cliente',
        ];
    }

    /**
     * Uma linha só no log de erro, a do projeto, com a classe do erro do envio
     * e o arquivo de quem mandou o e-mail, sem o endereço em lugar nenhum.
     */
    private function assertMailErrorLoggedWithoutTheAddress(string $address, string $file): void
    {
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && array_keys($context) === ['class', 'code', 'file']
            && $context['class'] === TransportException::class
            && str_starts_with($context['file'], $file)
            && ! str_contains((string) json_encode($context), $address));
    }
}
