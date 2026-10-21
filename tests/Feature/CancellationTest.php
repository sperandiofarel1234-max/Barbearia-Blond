<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ReminderStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_cancela_o_proprio_agendamento_pelo_token(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');

        $this->post(route('appointments.cancel', $a->manage_token))
            ->assertRedirect(route('appointments.manage', $a->manage_token))
            ->assertSessionHas('success');

        $a->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $a->status);
        $this->assertNotNull($a->cancelled_at);
        $this->assertDatabaseHas('appointments', ['id' => $a->id]); // registro preservado
        $this->assertDatabaseHas('appointment_histories', ['appointment_id' => $a->id, 'action' => 'cancelled', 'old_date' => '2026-11-03 00:00:00']);
    }

    public function test_token_invalido_ou_inexistente_retorna_404_e_nao_altera_nada(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');

        foreach ([(string) $a->id, '1', str_repeat('a', 64), 'token-invalido', strtoupper($a->manage_token)] as $bad) {
            $this->get(route('appointments.manage', $bad))->assertNotFound();
            $this->post(route('appointments.cancel', $bad))->assertNotFound();
            $this->get(route('appointments.reschedule', $bad))->assertNotFound();
            $this->post(route('appointments.reschedule.store', $bad), ['date' => '2026-11-04', 'time' => '10:00'])->assertNotFound();
        }

        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
    }

    public function test_token_de_um_agendamento_nao_acessa_outro(): void
    {
        $service = $this->service();
        $a = $this->book($service, '2026-11-03', '10:00');
        $b = $this->book($service, '2026-11-03', '11:00', ['customer_name' => 'Bruno Lima']);

        $this->post(route('appointments.cancel', $a->manage_token));

        $this->assertSame(AppointmentStatus::Cancelled, $a->fresh()->status);
        $this->assertSame(AppointmentStatus::Confirmed, $b->fresh()->status);
        $this->get(route('appointments.manage', $a->manage_token))->assertDontSee('Bruno Lima');
    }

    public function test_token_nao_e_armazenado_em_texto_puro(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $row = DB::table('appointments')->where('id', $a->id)->first();

        $this->assertSame(64, strlen($a->manage_token));
        $this->assertSame(hash('sha256', $a->manage_token), $row->manage_token_hash);
        $this->assertStringNotContainsString($a->manage_token, json_encode($row));
    }

    public function test_rejeita_cancelamento_fora_da_politica_de_antecedencia(): void
    {
        // Agora = 08:00. Atendimento 09:30 está a 1h30: dentro da janela proibida (< 2h).
        $a = $this->book($this->service(), '2026-11-02', '09:30');

        $this->post(route('appointments.cancel', $a->manage_token))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->get(route('appointments.manage', $a->manage_token))->assertSee('já passou');
    }

    public function test_cancelamento_com_exatamente_duas_horas_de_antecedencia_e_permitido(): void
    {
        $a = $this->book($this->service(), '2026-11-02', '10:00');

        $this->post(route('appointments.cancel', $a->manage_token))->assertSessionHas('success');
        $this->assertSame(AppointmentStatus::Cancelled, $a->fresh()->status);
    }

    public function test_politica_e_aplicada_no_backend_mesmo_sem_passar_pela_interface(): void
    {
        $a = $this->book($this->service(), '2026-11-02', '09:00');

        $this->expectException(BookingException::class);
        app(BookingService::class)->cancel($a);
    }

    public function test_nao_cancela_duas_vezes_nem_concluido(): void
    {
        $service = $this->service();
        $a = $this->book($service, '2026-11-03', '10:00');
        $this->post(route('appointments.cancel', $a->manage_token));

        $this->post(route('appointments.cancel', $a->manage_token))->assertSessionHas('error');
        $this->assertDatabaseCount('appointment_histories', 2); // created + cancelled (sem duplicar)

        $done = Appointment::factory()->at('2026-11-03', '14:00')->status(AppointmentStatus::Completed)->create();
        $this->post(route('appointments.cancel', $done->manage_token))->assertSessionHas('error');
        $this->assertSame(AppointmentStatus::Completed, $done->fresh()->status);
    }

    public function test_horario_cancelado_volta_a_aparecer_na_disponibilidade(): void
    {
        $service = $this->service(40);
        $a = $this->book($service, '2026-11-03', '10:00');
        $availability = app(AvailabilityService::class);
        $day = CarbonImmutable::parse('2026-11-03', 'America/Sao_Paulo');

        $this->assertNotContains('10:00', $availability->slots(40, $day));
        $this->post(route('appointments.cancel', $a->manage_token));
        $this->assertContains('10:00', $availability->slots(40, $day));

        $this->get(route('booking.create', ['service' => $service, 'date' => '2026-11-03']))->assertSee('10:00');
    }

    public function test_cancelamento_invalida_lembretes_pendentes(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->assertSame(2, $a->reminders()->where('status', 'pending')->count());

        $this->post(route('appointments.cancel', $a->manage_token));

        $this->assertSame(0, $a->reminders()->where('status', 'pending')->count());
        $this->assertSame(2, $a->reminders()->where('status', ReminderStatus::Cancelled->value)->count());
    }

    public function test_transicoes_de_status_incompativeis_sao_bloqueadas(): void
    {
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Confirmed)); // não volta a ocupar
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Confirmed));
    }

    public function test_pagina_de_gerenciamento_nao_vaza_token_por_referer_nem_cache(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');

        $this->get(route('appointments.manage', $a->manage_token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_rotas_publicas_sao_limitadas_por_throttle(): void
    {
        $limited = false;
        for ($i = 0; $i < 40; $i++) {
            if ($this->get(route('appointments.manage', str_repeat('b', 64)))->status() === 429) {
                $limited = true;
                break;
            }
        }
        $this->assertTrue($limited, 'Esperado HTTP 429 após muitas tentativas (proteção contra enumeração).');
    }
}
