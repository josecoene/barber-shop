<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Os 4 serviços de exemplo. O DemoSeeder usa a mesma lista, mas só cria os
     * que faltam.
     *
     * @var list<array{name: string, duration: int, price: float, status: string}>
     */
    public const SAMPLES = [
        ['name' => 'Corte de cabelo', 'duration' => 30, 'price' => 40.00, 'status' => 'active'],
        ['name' => 'Barba',           'duration' => 20, 'price' => 25.00, 'status' => 'active'],
        ['name' => 'Corte + Barba',   'duration' => 45, 'price' => 60.00, 'status' => 'active'],
        ['name' => 'Acabamento',      'duration' => 15, 'price' => 15.00, 'status' => 'active'],
    ];

    /**
     * Cria os serviços de exemplo ou devolve os que já existem (pelo nome) ao
     * preço, à duração e ao status padrão. Os outros serviços ficam como estão.
     */
    public function run(): void
    {
        foreach (self::SAMPLES as $service) {
            Service::updateOrCreate(['name' => $service['name']], $service);
        }
    }
}
