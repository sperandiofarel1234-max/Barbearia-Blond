<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Corte', 'Corte de cabelo', 40, '50.00'],
            ['Barba', 'Barba na navalha', 20, '30.00'],
            ['Corte e barba', 'Combo completo', 60, '70.00'],
        ] as [$name, $description, $minutes, $price]) {
            Service::updateOrCreate(
                ['name' => $name],
                ['description' => $description, 'duration_minutes' => $minutes, 'price' => $price, 'active' => true],
            );
        }
    }
}
