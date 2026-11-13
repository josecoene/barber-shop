<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_does_not_duplicate_records(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(4, Service::count());
        $this->assertSame(1, User::count());
    }

    public function test_seeding_again_restores_the_sample_services_and_keeps_the_others(): void
    {
        $this->seed();

        Service::where('name', 'Corte de cabelo')->update(['price' => 45.00, 'duration' => 35]);
        Service::where('name', 'Acabamento')->update(['status' => 'inactive']);
        Service::create(['name' => 'Pigmentação', 'duration' => 40, 'price' => 50.00, 'status' => 'inactive']);

        $this->seed();

        $haircut = Service::firstWhere('name', 'Corte de cabelo');
        $this->assertEquals(40.0, (float) $haircut->price);
        $this->assertEquals(30, (int) $haircut->duration);
        $this->assertSame('active', Service::firstWhere('name', 'Acabamento')->status);

        $custom = Service::firstWhere('name', 'Pigmentação');
        $this->assertEquals(50.0, (float) $custom->price);
        $this->assertSame('inactive', $custom->status);
        $this->assertSame(5, Service::count());
    }

    public function test_seeds_the_test_user(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
