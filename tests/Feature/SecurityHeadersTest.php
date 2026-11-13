<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cabeçalhos de segurança, hosts e proxies confiáveis, e links dos e-mails.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * IP privado da rede local, que o trustHosts aceita por causa do health check do ALB.
     */
    private const PRIVATE_HOST = 'http://192.168.0.23';

    /**
     * Passa o teste para o modo produção: o TrustHosts começa a conferir o Host (nos
     * testes ele não confere) e o AppServiceProvider fixa a raiz dos links no APP_URL.
     */
    private function bootAsProduction(): void
    {
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->boot();
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_every_response_has_the_security_headers(): void
    {
        $this->withoutVite();

        $this->assertSecurityHeaders($this->get('/login')->assertOk());
        $this->assertSecurityHeaders($this->getJson('/api/health')->assertOk());

        // Também nas respostas de erro.
        $this->assertSecurityHeaders($this->getJson('/api/services')->assertUnauthorized());
        $this->assertSecurityHeaders($this->getJson('/api/rota-que-nao-existe')->assertNotFound());
        $this->assertSecurityHeaders($this->get('/pagina-que-nao-existe')->assertNotFound());
    }

    public function test_content_security_policy_only_when_enabled(): void
    {
        config(['barberflow.security.csp' => true]);

        $this->getJson('/api/health')->assertHeader(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
            ."connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );

        config(['barberflow.security.csp' => false]);

        $this->getJson('/api/health')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_hsts_only_on_secure_requests(): void
    {
        $this->getJson('https://localhost/api/health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $this->getJson('http://localhost/api/health')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_from_a_trusted_proxy_counts_as_secure(): void
    {
        config(['barberflow.security.trusted_proxies' => '10.0.0.0/8']);
        (new AppServiceProvider($this->app))->boot();

        // O ALB termina o HTTPS e repassa em HTTP, avisando pelo X-Forwarded-Proto.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.20'])
            ->getJson('http://localhost/api/health', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security');

        // Quem não é proxy confiável não consegue fingir HTTPS.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('http://localhost/api/health', ['X-Forwarded-Proto' => 'https'])
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_cors_never_opens_the_api_to_an_unlisted_site(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));

        $allowed = config('cors.allowed_origins')[0];

        $this->getJson('/api/health', ['Origin' => $allowed])
            ->assertHeader('Access-Control-Allow-Origin', $allowed)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $response = $this->getJson('/api/health', ['Origin' => 'https://site-de-fora.example']);

        $this->assertNotContains($response->headers->get('Access-Control-Allow-Origin'), ['https://site-de-fora.example', '*']);
    }

    public function test_trusted_hosts_are_the_app_host_and_private_ips(): void
    {
        $patterns = config('barberflow.security.trusted_hosts');

        // O middleware usa a lista da config.
        $this->assertSame($patterns, $this->app->make(TrustHosts::class)->hosts());

        $trusted = fn (string $host): bool => collect($patterns)
            ->contains(fn (string $pattern) => preg_match('{'.$pattern.'}i', $host) === 1);

        $this->assertTrue($trusted(parse_url(config('app.url'), PHP_URL_HOST)));

        foreach (['10.0.1.25', '172.16.0.4', '172.31.255.1', '192.168.0.10'] as $privateIp) {
            $this->assertTrue($trusted($privateIp), $privateIp);
        }

        foreach (['172.32.0.1', '8.8.8.8', 'evil.example.com', '10.0.0.1.evil.example.com'] as $host) {
            $this->assertFalse($trusted($host), $host);
        }
    }

    /**
     * Sem a raiz fixa, quem pedisse o "esqueci minha senha" de outra pessoa com o Host de um
     * IP da rede dele faria o e-mail dela levar o token para esse endereço.
     */
    public function test_password_reset_link_uses_the_app_url_whatever_the_host(): void
    {
        Notification::fake();
        $this->bootAsProduction();
        $user = $this->makeClientUser();

        // O TrustHosts está ligado: host de fora da lista recebe 400, e o IP privado passa.
        $this->postJson('http://site-de-fora.example/api/forgot-password', ['email' => $user->email])->assertStatus(400);
        $this->postJson(self::PRIVATE_HOST.'/api/forgot-password', ['email' => $user->email])->assertOk();

        $link = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $link);
    }

    /**
     * O mesmo vale para o link de confirmação de e-mail: com ele, quem criasse a conta com o
     * e-mail de outra pessoa conseguiria a confirmação dela e a ligação ao cadastro dela.
     */
    public function test_confirmation_link_uses_the_app_url_and_does_not_work_with_another_host(): void
    {
        Notification::fake();
        $this->withoutVite();
        $this->bootAsProduction();
        $appUrl = rtrim(config('app.url'), '/');

        $this->postJson(self::PRIVATE_HOST.'/api/register', [
            'name' => 'Ana',
            'email' => 'ana@barberflow.test',
            'password' => 'senha-da-ana',
            'password_confirmation' => 'senha-da-ana',
        ])->assertCreated();

        $user = User::firstWhere('email', 'ana@barberflow.test');
        $link = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertStringStartsWith($appUrl.'/email/verify/', $link);

        // A assinatura cobre o endereço inteiro: o mesmo link com outro Host não confirma nada.
        $this->actingAs($user)->get(str_replace($appUrl, self::PRIVATE_HOST, $link))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user)->get($link)->assertRedirect('/');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
