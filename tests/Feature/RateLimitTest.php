<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Limite de requisições da API (limitador 'api', valor em config/barberflow.php).
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_limit_comes_from_the_config(): void
    {
        $this->freezeSecond();
        config(['barberflow.rate_limits.api' => 2]);
        $this->actingAsAdmin();

        $this->getJson('/api/services')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 1);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', 0);

        $this->getJson('/api/services')
            ->assertStatus(429)
            ->assertHeader('Retry-After', 60)
            ->assertHeader('X-RateLimit-Limit', 2)
            ->assertHeader('X-RateLimit-Remaining', 0)
            ->assertExactJson(['message' => 'Muitas requisições. Tente de novo em 60 segundos.']);
    }

    public function test_each_user_has_its_own_count(): void
    {
        config(['barberflow.rate_limits.api' => 1]);

        $this->actingAsAdmin();
        $this->getJson('/api/services')->assertOk();
        $this->getJson('/api/services')->assertStatus(429);

        $this->actingAsBarber();
        $this->getJson('/api/services')->assertOk();
    }

    public function test_visitors_are_counted_by_ip(): void
    {
        config(['barberflow.rate_limits.api' => 1]);

        $this->postJson('/api/login', [])->assertUnprocessable();
        $this->postJson('/api/login', [])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_health_is_not_limited(): void
    {
        config(['barberflow.rate_limits.api' => 1]);

        foreach (range(1, 3) as $call) {
            $this->getJson('/api/health')
                ->assertOk()
                ->assertHeaderMissing('X-RateLimit-Limit');
        }
    }
}
