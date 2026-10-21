<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function reschedule(Appointment $a, string $date, string $time)
    {
        return $this->post(route('appointments.reschedule.store', $a->manage_token), ['date' => $date, 'time' => $time]);
    }

    public function test_remarcacao_valida_atualiza_data_horario_final_e_historico(): void
    {
        $service = $this->service(40, '50.00');
        $a = $this->book($service, '2026-11-03', '10:00');

        $this->reschedule($a, '2026-11-04', '14:00')
            ->assertRedirect(route('appointments.manage', $a->manage_token))->assertSessionHas('success');

        $a->refresh();
        $this->assertSame('2026-11-04', $a->dateString());
        $this->assertSame('14:00:00', $a->start_time);
        $this->assertSame('14:40:00', $a->end_time); // recalculado pela duração contratada
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $a->id, 'action' => 'rescheduled',
            'old_date' => '2026-11-03 00:00:00', 'old_start_time' => '10:00:00',
            'new_date' => '2026-11-04 00:00:00', 'new_start_time' => '14:00:00',
        ]);

        // O horário antigo foi liberado e o novo ocupado.
        $availability = app(AvailabilityService::class);
        $this->assertContains('10:00', $availability->slots(40, CarbonImmutable::parse('2026-11-03', 'America/Sao_Paulo')));
        $this->assertNotContains('14:00', $availability->slots(40, CarbonImmutable::parse('2026-11-04', 'America/Sao_Paulo')));
    }

    public function test_preserva_preco_e_duracao_contratados_mesmo_apos_o_servico_mudar(): void
    {
        $service = $this->service(40, '50.00');
        $a = $this->book($service, '2026-11-03', '10:00');
        $service->update(['price' => '120.00', 'duration_minutes' => 90]);

        $this->reschedule($a, '2026-11-04', '10:00')->assertSessionHas('success');

        $a->refresh();
        $this->assertSame('50.00', $a->price);
        $this->assertSame(40, $a->duration_minutes);
        $this->assertSame('10:40:00', $a->end_time);
    }

    public function test_rejeita_novo_horario_ocupado_e_preserva_o_agendamento_original(): void
    {
        $service = $this->service(40);
        $a = $this->book($service, '2026-11-03', '10:00');
        $this->book($service, '2026-11-04', '15:00', ['customer_name' => 'Bruno Lima']);

        $this->reschedule($a, '2026-11-04', '15:20')->assertSessionHas('error'); // sobrepõe 15:00–15:40

        $a->refresh();
        $this->assertSame('2026-11-03', $a->dateString());
        $this->assertSame('10:00:00', $a->start_time);
        $this->assertSame('10:40:00', $a->end_time);
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertDatabaseMissing('appointment_histories', ['appointment_id' => $a->id, 'action' => 'rescheduled']);
    }

    public function test_remarcacao_invalida_nao_perde_o_original(): void
    {
        $a = $this->book($this->service(40), '2026-11-03', '10:00');

        foreach ([['2026-11-08', '10:00'], ['2026-11-04', '08:00'], ['2026-11-04', '17:30'], ['2026-10-30', '10:00'], ['2026-11-04', '10:05']] as [$d, $t]) {
            $this->reschedule($a, $d, $t)->assertSessionHas('error');
        }

        $a->refresh();
        $this->assertSame('2026-11-03', $a->dateString());
        $this->assertSame('10:00:00', $a->start_time);
    }

    public function test_pode_remarcar_para_horario_que_sobrepoe_o_proprio_horario_atual(): void
    {
        $a = $this->book($this->service(40), '2026-11-03', '10:00'); // 10:00–10:40

        $this->reschedule($a, '2026-11-03', '10:20')->assertSessionHas('success');
        $this->assertSame('10:20:00', $a->fresh()->start_time);
    }

    public function test_rejeita_remarcar_para_o_mesmo_horario(): void
    {
        $a = $this->book($this->service(40), '2026-11-03', '10:00');
        $this->reschedule($a, '2026-11-03', '10:00')->assertSessionHas('error');
    }

    public function test_remarcacao_respeita_a_politica_de_antecedencia(): void
    {
        $a = $this->book($this->service(40), '2026-11-02', '09:00'); // faltam 1h
        $this->reschedule($a, '2026-11-04', '10:00')->assertSessionHas('error');
        $this->assertSame('2026-11-02', $a->fresh()->dateString());
        $this->get(route('appointments.reschedule', $a->manage_token))->assertRedirect(route('appointments.manage', $a->manage_token));
    }

    public function test_nao_remarca_cancelado_nem_concluido(): void
    {
        $cancelled = Appointment::factory()->at('2026-11-04', '10:00')->status(AppointmentStatus::Cancelled)->create();
        $done = Appointment::factory()->at('2026-11-04', '12:00')->status(AppointmentStatus::Completed)->create();

        $this->reschedule($cancelled, '2026-11-05', '10:00')->assertSessionHas('error');
        $this->reschedule($done, '2026-11-05', '12:00')->assertSessionHas('error');
        $this->assertSame('2026-11-04', $cancelled->fresh()->dateString());
        $this->assertSame('2026-11-04', $done->fresh()->dateString());
    }

    public function test_tela_de_remarcacao_lista_horarios_sem_contar_o_proprio_agendamento(): void
    {
        $service = $this->service(40);
        $a = $this->book($service, '2026-11-03', '10:00');
        $this->book($service, '2026-11-03', '11:00', ['customer_name' => 'Bruno Lima']);

        $page = $this->get(route('appointments.reschedule', ['token' => $a->manage_token, 'date' => '2026-11-03']))->assertOk();
        $page->assertSee('value="10:00"', false);   // próprio horário aparece como livre
        $page->assertDontSee('value="11:00"', false); // ocupado por outro
    }

    public function test_validacao_dos_campos_da_remarcacao(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->post(route('appointments.reschedule.store', $a->manage_token), ['date' => 'x', 'time' => ''])->assertSessionHasErrors(['date', 'time']);
    }
}
