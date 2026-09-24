<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Corte de cabelo', 'duration' => 30, 'price' => 40.00, 'status' => 'active'],
            ['name' => 'Barba',           'duration' => 20, 'price' => 25.00, 'status' => 'active'],
            ['name' => 'Corte + Barba',   'duration' => 45, 'price' => 60.00, 'status' => 'active'],
            ['name' => 'Acabamento',      'duration' => 15, 'price' => 15.00, 'status' => 'active'],
        ];

        foreach ($services as $service) {
            Service::create($service);
        }
    }
}