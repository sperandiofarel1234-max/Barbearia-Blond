<?php

namespace Tests\Feature;

use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Tests\TestCase;

/**
 * Concorrência real: processos PHP separados disputam o mesmo horário no MySQL.
 * Usa DatabaseMigrations (dados commitados) porque os processos filhos precisam enxergá-los.
 * Roda com o relógio real, pois os filhos não herdam o travelTo.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelBack();
    }

    private function nextOpenDay(int $minDaysAhead = 3): string
    {
        $d = CarbonImmutable::now('America/Sao_Paulo')->addDays($minDaysAhead);
        while ($d->dayOfWeekIso === 7) {
            $d = $d->addDay();
        }

        return $d->format('Y-m-d');
    }

    public function test_apenas_um_de_varios_processos_simultaneos_ocupa_o_mesmo_horario(): void
    {
        $service = Service::factory()->minutes(40)->create();
        $date = $this->nextOpenDay();

        $attempt = function (int $n) use ($service, $date) {
            try {
                app(BookingService::class)->create($service, [
                    'customer_name' => "Cliente $n", 'customer_phone' => '11987654321', 'customer_email' => "c$n@example.com",
                ], $date, '10:00');

                return 'ok';
            } catch (BookingException) {
                return 'conflito';
            }
        };

        // Atenção: UMA closure por linha (limitação do laravel/serializable-closure).
        $results = Concurrency::driver('process')->run([
            fn () => $attempt(1),
            fn () => $attempt(2),
            fn () => $attempt(3),
            fn () => $attempt(4),
            fn () => $attempt(5),
            fn () => $attempt(6),
        ]);

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')), 'Resultados: '.json_encode($results));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r === 'conflito')));
        $this->assertSame(1, Appointment::where('date', $date)->count());
    }

    public function test_horarios_sobrepostos_em_paralelo_nunca_geram_sobreposicao(): void
    {
        $service = Service::factory()->minutes(40)->create();
        $date = $this->nextOpenDay(4);

        // Cada processo devolve "horário:resultado": a verificação não depende da ordem de retorno.
        $attempt = function (string $time) use ($service, $date) {
            try {
                app(BookingService::class)->create($service, [
                    'customer_name' => 'Cliente', 'customer_phone' => '11987654321', 'customer_email' => 'c@example.com',
                ], $date, $time);

                return "$time|ok";
            } catch (BookingException) {
                return "$time|conflito";
            } catch (\Throwable $e) {
                return "$time|ERRO ".class_basename($e).' '.substr($e->getMessage(), 0, 200);
            }
        };

        // 10:00–10:40, 10:10–10:50, 10:20–11:00 e 10:30–11:10 se sobrepõem entre si;
        // 10:40–11:20 encosta no primeiro; 11:20–12:00 não conflita com nenhum.
        // Atenção: UMA closure por linha (limitação do laravel/serializable-closure).
        $results = Concurrency::driver('process')->run([
            fn () => $attempt('10:00'),
            fn () => $attempt('10:10'),
            fn () => $attempt('10:20'),
            fn () => $attempt('10:30'),
            fn () => $attempt('10:40'),
            fn () => $attempt('11:20'),
        ]);

        $outcome = [];
        foreach ($results as $r) {
            [$time, $status] = explode('|', $r, 2) + [1 => ''];
            $outcome[$time] = $status;
        }
        $detail = json_encode($results);

        // Nenhuma falha inesperada (deadlock, erro SQL...).
        $this->assertSame([], array_values(array_filter($outcome, fn ($s) => ! in_array($s, ['ok', 'conflito'], true))), $detail);

        // Nada se sobrepõe no banco.
        $rows = Appointment::where('date', $date)->orderBy('start_time')->get();
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertLessThanOrEqual($rows[$i]->start_time, $rows[$i - 1]->end_time, 'Sobreposição detectada no banco: '.$detail);
        }

        // O que o processo reportou como "ok" é exatamente o que está no banco.
        $okTimes = array_keys(array_filter($outcome, fn ($s) => $s === 'ok'));
        sort($okTimes);
        $dbTimes = $rows->map(fn ($r) => substr($r->start_time, 0, 5))->all();
        $this->assertSame($okTimes, $dbTimes, $detail);

        // 11:20 não conflita com nenhum outro horário: sempre aceito.
        $this->assertSame('ok', $outcome['11:20'], $detail);
    }
}
