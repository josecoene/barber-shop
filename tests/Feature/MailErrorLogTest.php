<?php

namespace Tests\Feature;

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
 */
class MailErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Falha no envio de e-mail.';

    private const ADDRESS = 'maria.sigilosa@barberflow.test';

    private const SENT = 'Se o e-mail estiver cadastrado, você vai receber o link para redefinir a senha.';

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

    public function test_registration_with_the_email_down_logs_without_the_address(): void
    {
        $this->failMailDelivery();
        Log::spy();

        // O cadastro não esconde a falha no envio do link de confirmação: hoje ela é um erro do servidor.
        $this->postJson('/api/register', [
            'name' => 'Maria Sigilosa',
            'email' => self::ADDRESS,
            'password' => 'senha-da-cliente',
            'password_confirmation' => 'senha-da-cliente',
        ])->assertServerError();

        $this->assertMailErrorLoggedWithoutTheAddress(self::ADDRESS, 'app/Services/Auth/ClientRegistration.php:');
    }

    public function test_resending_the_confirmation_with_the_email_down_logs_without_the_address(): void
    {
        $user = Sanctum::actingAs($this->makeClientUser(verified: false));
        $this->failMailDelivery();
        Log::spy();

        $this->postJson('/api/email/verification-notification')->assertServerError();

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Http/Controllers/AuthController.php:');
    }

    public function test_resending_the_confirmation_on_the_screen_with_the_email_down_logs_without_the_address(): void
    {
        $this->withoutVite();
        $user = $this->makeClientUser(verified: false);
        $this->failMailDelivery();
        Log::spy();

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertServerError();

        $this->assertMailErrorLoggedWithoutTheAddress($user->email, 'app/Http/Controllers/Auth/EmailVerificationController.php:');
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
