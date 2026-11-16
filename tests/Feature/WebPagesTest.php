<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public static function pages(): array
    {
        return [
            'login' => ['/login'],
            'barbers' => ['/barbers'],
            'clients' => ['/clients'],
            'services' => ['/services'],
            'appointments' => ['/appointments'],
            'new appointment' => ['/appointments/create'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_responds_ok(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public function test_menu_links_point_to_the_pages_and_not_to_the_api(): void
    {
        $response = $this->get('/appointments')->assertOk();

        foreach (['barbers', 'clients', 'services'] as $page) {
            $url = route("{$page}.index");

            $this->assertStringEndsWith("/{$page}", $url);
            $this->assertStringNotContainsString('/api/', $url);
            $response->assertSee('href="'.$url.'"', false);
        }
    }

    public function test_route_names_are_unique(): void
    {
        $duplicates = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RouteDefinition $route) => $route->getName())
            ->filter()
            ->duplicates()
            ->values()
            ->all();

        $this->assertSame([], $duplicates);
    }
}
