<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_runs_in_brazilian_portuguese(): void
    {
        $this->assertSame('pt_BR', app()->getLocale());
        $this->assertSame('O campo nome é obrigatório.', __('validation.required', ['attribute' => __('validation.attributes.name')]));
    }

    public function test_app_uses_the_sao_paulo_timezone(): void
    {
        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', date_default_timezone_get());
        $this->assertSame('America/Sao_Paulo', now()->timezoneName);
    }

    public function test_pagination_links_are_in_portuguese(): void
    {
        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('meta.links.0.label', '&laquo; Anterior')
            ->assertJsonPath('meta.links.2.label', 'Próxima &raquo;');
    }
}
