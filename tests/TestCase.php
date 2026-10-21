<?php

namespace Tests;

use App\Models\Appointment;
use App\Models\Service;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** "Agora" fixo: segunda-feira, 02/11/2026, 08:00 (America/Sao_Paulo). */
    protected const NOW = '2026-11-02 08:00:00';

    /** Antecedência mínima (h) assumida pelos testes de cancelamento/remarcação. */
    protected const CANCEL_MIN_HOURS = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/Sao_Paulo'));

        // Política fixa nos testes, independente do .env de quem executa.
        config(['barbearia.cancel_min_hours' => self::CANCEL_MIN_HOURS]);
    }

    protected function service(int $minutes = 40, string $price = '50.00', bool $active = true): Service
    {
        return Service::factory()->minutes($minutes)->create(['price' => $price, 'active' => $active]);
    }

    protected function customer(array $override = []): array
    {
        return $override + [
            'customer_name' => 'Ana Souza',
            'customer_phone' => '11987654321',
            'customer_email' => 'ana@example.com',
        ];
    }

    protected function book(Service $service, string $date, string $time, array $customer = []): Appointment
    {
        return app(BookingService::class)->create($service, $this->customer($customer), $date, $time);
    }
}
