<?php

namespace Tests\Feature;

use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agendamento_valido_via_http_calcula_fim_preco_e_duracao_no_servidor(): void
    {
        $service = $this->service(40, '50.00');

        $response = $this->post(route('booking.store', $service), $this->customer([
            'date' => '2026-11-03', 'time' => '09:00',
            // Tentativas de adulteração: devem ser ignoradas.
            'price' => '0.01', 'duration_minutes' => 5, 'end_time' => '09:05', 'status' => 'completed', 'service_id' => 999,
        ]));

        $appointment = Appointment::firstOrFail();
        $response->assertRedirect(route('appointments.manage', $appointment->manage_token));

        $this->assertSame('09:00:00', $appointment->start_time);
        $this->assertSame('09:40:00', $appointment->end_time);
        $this->assertSame(40, $appointment->duration_minutes);
        $this->assertSame('50.00', $appointment->price);
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame($service->id, $appointment->service_id);
        $this->assertSame('11987654321', $appointment->customer_phone);
    }

    public function test_pagina_de_confirmacao_mostra_resumo(): void
    {
        $service = $this->service(40, '50.00');
        $appointment = $this->book($service, '2026-11-03', '09:00');

        $this->get(route('appointments.manage', $appointment->manage_token))
            ->assertOk()->assertSee($service->name)->assertSee('03/11/2026')->assertSee('09:00 às 09:40')
            ->assertSee('40 min')->assertSee('R$ 50,00');
    }

    public function test_rejeita_data_passada_e_horario_que_ja_passou(): void
    {
        $service = $this->service();
        $this->assertRejected(fn () => $this->book($service, '2026-10-30', '10:00'), 'passou');
        $this->assertRejected(fn () => $this->book($service, '2026-11-02', '08:00'), 'passou'); // exatamente agora
        $this->assertRejected(fn () => $this->book($service, '2026-11-02', '07:00'), 'passou');
        $this->assertSame(0, Appointment::count());
    }

    public function test_rejeita_domingo(): void
    {
        $this->assertRejected(fn () => $this->book($this->service(), '2026-11-08', '10:00'), 'domingos');
    }

    public function test_sabado_e_atendido(): void
    {
        $this->assertSame('2026-11-07', $this->book($this->service(), '2026-11-07', '10:00')->dateString());
    }

    public function test_rejeita_antes_das_9h_e_depois_das_18h(): void
    {
        $service = $this->service(40);
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '08:50'), 'começar a partir das 09:00');
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '18:00'), 'fechamento');
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '19:00'), 'fechamento');
    }

    public function test_termino_exato_as_18h_e_aceito_e_18h10_e_rejeitado(): void
    {
        $service = $this->service(40);
        $this->assertSame('18:00:00', $this->book($service, '2026-11-03', '17:20')->end_time);   // termina 18:00
        $this->assertRejected(fn () => $this->book($service, '2026-11-04', '17:30'), '18:10');   // terminaria 18:10
    }

    public function test_rejeita_horario_fora_da_grade_de_10_minutos(): void
    {
        $this->assertRejected(fn () => $this->book($this->service(), '2026-11-03', '09:05'), 'horários disponíveis');
    }

    public function test_rejeita_data_e_hora_invalidas(): void
    {
        $service = $this->service();
        $this->assertRejected(fn () => $this->book($service, '2026-02-31', '10:00'), 'inválido');
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '25:00'), 'inválido');
    }

    public function test_rejeita_sobreposicao(): void
    {
        $service = $this->service(40);
        $this->book($service, '2026-11-03', '09:00'); // 09:00–09:40

        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '09:00'), 'ocupado');  // mesmo horário
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '09:30'), 'ocupado');  // início dentro
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '08:30'), 'começar');  // antes da abertura
        $this->assertSame(1, Appointment::count());
    }

    public function test_aceita_atendimentos_consecutivos_e_rejeita_conflito_com_duracoes_diferentes(): void
    {
        $corte = $this->service(40);
        $barba = $this->service(20);
        $combo = $this->service(60);

        $this->book($corte, '2026-11-03', '09:00');                       // 09:00–09:40
        $this->book($barba, '2026-11-03', '09:40');                       // 09:40–10:00 (encostado: válido)

        $this->assertRejected(fn () => $this->book($barba, '2026-11-03', '09:20'), 'ocupado'); // 09:20–09:40 conflita com corte
        $this->assertRejected(fn () => $this->book($combo, '2026-11-03', '09:10'), 'ocupado'); // 09:10–10:10 engloba os dois
        $this->assertRejected(fn () => $this->book($combo, '2026-11-03', '09:30'), 'ocupado'); // atravessa a fronteira

        $this->book($combo, '2026-11-03', '10:00');                       // 10:00–11:00 encostado
        $this->assertSame(3, Appointment::count());
    }

    public function test_novo_servico_longo_nao_cabe_antes_de_um_agendamento_existente(): void
    {
        $this->book($this->service(40), '2026-11-03', '10:00'); // 10:00–10:40
        // combo de 60 min às 09:30 terminaria 10:30 e invadiria o existente
        $this->assertRejected(fn () => $this->book($this->service(60), '2026-11-03', '09:30'), 'ocupado');
        // às 09:00 termina exatamente às 10:00: válido
        $this->assertSame('10:00:00', $this->book($this->service(60), '2026-11-03', '09:00')->end_time);
    }

    public function test_disponibilidade_reflete_as_mesmas_regras_do_backend(): void
    {
        $service = $this->service(40);
        $this->book($service, '2026-11-03', '09:00');
        $availability = app(AvailabilityService::class);
        $day = CarbonImmutable::parse('2026-11-03', 'America/Sao_Paulo');

        $slots = $availability->slots(40, $day);

        $this->assertNotContains('09:00', $slots);
        $this->assertNotContains('09:30', $slots);
        $this->assertContains('09:40', $slots);   // encostado
        $this->assertContains('17:20', $slots);   // termina 18:00
        $this->assertNotContains('17:30', $slots);

        // Todo horário oferecido é aceito pelo backend; todo horário não oferecido é rejeitado.
        foreach ($slots as $slot) {
            $this->assertTrue($this->tryBook($service, '2026-11-03', $slot, rollback: true), "Oferecido mas rejeitado: $slot");
        }
        foreach (['09:00', '09:10', '09:30', '17:30', '17:50'] as $slot) {
            $this->assertFalse($this->tryBook($service, '2026-11-03', $slot, rollback: true), "Não oferecido mas aceito: $slot");
        }
    }

    public function test_slots_de_hoje_e_domingo(): void
    {
        $availability = app(AvailabilityService::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:25:00', 'America/Sao_Paulo'));
        $slots = $availability->slots(40, CarbonImmutable::parse('2026-11-02', 'America/Sao_Paulo'));
        $this->assertNotContains('09:20', $slots);
        $this->assertSame('09:30', $slots[0]);
        $this->assertSame([], $availability->slots(40, CarbonImmutable::parse('2026-11-08', 'America/Sao_Paulo')));
    }

    public function test_validacao_do_formulario_publico(): void
    {
        $service = $this->service();
        $base = ['date' => '2026-11-03', 'time' => '10:00'];

        $this->post(route('booking.store', $service), $base + $this->customer(['customer_name' => '']))->assertSessionHasErrors('customer_name');
        $this->post(route('booking.store', $service), $base + $this->customer(['customer_phone' => '123']))->assertSessionHasErrors('customer_phone');
        $this->post(route('booking.store', $service), $base + $this->customer(['customer_email' => 'nao-e-email']))->assertSessionHasErrors('customer_email');
        $this->post(route('booking.store', $service), $this->customer(['date' => 'x', 'time' => '10:00']))->assertSessionHasErrors('date');
        $this->assertSame(0, Appointment::count());
    }

    public function test_erro_de_regra_volta_com_mensagem_clara_sem_criar_registro(): void
    {
        $service = $this->service();
        $this->post(route('booking.store', $service), $this->customer(['date' => '2026-11-08', 'time' => '10:00']))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, Appointment::count());
    }

    public function test_home_e_pagina_de_horarios_renderizam(): void
    {
        $service = $this->service();
        $this->get('/')->assertOk()->assertSee($service->name);
        $this->get(route('booking.create', ['service' => $service, 'date' => '2026-11-03']))->assertOk()->assertSee('09:00');
        $this->get(route('booking.create', ['service' => $service, 'date' => '2026-11-08']))->assertOk()->assertSee('domingos');
    }

    public function test_conflito_com_agendamento_concluido_tambem_e_rejeitado_mas_cancelado_libera(): void
    {
        $service = $this->service(40);
        $a = $this->book($service, '2026-11-03', '09:00');
        $this->assertRejected(fn () => $this->book($service, '2026-11-03', '09:10'), 'ocupado');

        app(BookingService::class)->cancel($a);
        $this->assertSame('09:10:00', $this->book($service, '2026-11-03', '09:10')->start_time);
    }

    private function tryBook($service, string $date, string $time, bool $rollback): bool
    {
        try {
            DB::beginTransaction();
            $this->book($service, $date, $time, ['customer_email' => 'x@example.com']);
            $ok = true;
        } catch (BookingException) {
            $ok = false;
        } finally {
            if ($rollback) {
                DB::rollBack();
            }
        }

        return $ok;
    }

    private function assertRejected(callable $fn, string $messageContains): void
    {
        try {
            $fn();
        } catch (BookingException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());

            return;
        }
        $this->fail('Era esperada uma BookingException contendo: '.$messageContains);
    }
}
