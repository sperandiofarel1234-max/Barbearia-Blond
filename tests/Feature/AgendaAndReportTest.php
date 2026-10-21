<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaAndReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function make(string $date, string $start, string $price, AppointmentStatus $status, ?Service $service = null, array $extra = []): Appointment
    {
        return Appointment::factory()->at($date, $start)->priced($price)->status($status)
            ->create(['service_id' => ($service ?? Service::factory()->create())->id] + $extra);
    }

    // --- Proteção ---------------------------------------------------------------

    public function test_area_administrativa_exige_login_e_perfil_de_administrador(): void
    {
        $a = $this->make('2026-11-03', '10:00', '50.00', AppointmentStatus::Confirmed);
        $comum = User::factory()->create();

        $urls = ['/admin', '/admin/agenda', '/admin/faturamento', '/admin/servicos'];

        // Visitante: redirecionado ao login.
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post(route('admin.appointments.complete', $a))->assertRedirect(route('login'));

        // Usuário autenticado, porém não administrador: 403.
        foreach ($urls as $url) {
            $this->actingAs($comum)->get($url)->assertForbidden();
        }
        $this->actingAs($comum)->post(route('admin.appointments.complete', $a))->assertForbidden();
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
    }

    public function test_cliente_nao_ve_agenda_completa_nem_dados_de_outros(): void
    {
        $this->make('2026-11-03', '10:00', '50.00', AppointmentStatus::Confirmed, null, ['customer_name' => 'Cliente Secreto']);
        $this->get('/')->assertDontSee('Cliente Secreto');
        $this->get('/admin/agenda?date=2026-11-03')->assertRedirect(route('login'));
    }

    public function test_login_do_administrador(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'barbeiro@example.com', 'password' => 'segredo-forte-1']);
        $comum = User::factory()->create(['email' => 'comum@example.com', 'password' => 'segredo-forte-2']);

        $this->post('/login', ['email' => 'barbeiro@example.com', 'password' => 'errada'])->assertSessionHas('error');
        $this->assertGuest();
        $this->post('/login', ['email' => 'comum@example.com', 'password' => 'segredo-forte-2'])->assertSessionHas('error'); // não é admin
        $this->assertGuest();
        $this->post('/login', ['email' => 'barbeiro@example.com', 'password' => 'segredo-forte-1'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
    }

    public function test_login_tem_limite_de_tentativas(): void
    {
        $status = null;
        for ($i = 0; $i < 8; $i++) {
            $status = $this->post('/login', ['email' => 'x@example.com', 'password' => 'errada'])->status();
        }
        $this->assertSame(429, $status);
    }

    // --- Agenda -----------------------------------------------------------------

    public function test_agenda_lista_em_ordem_cronologica_com_os_dados_do_cliente(): void
    {
        $this->make('2026-11-03', '15:00', '50.00', AppointmentStatus::Confirmed, null, ['customer_name' => 'Terceiro Cliente']);
        $this->make('2026-11-03', '09:00', '50.00', AppointmentStatus::Confirmed, null, ['customer_name' => 'Primeiro Cliente', 'customer_phone' => '11911112222']);
        $this->make('2026-11-03', '11:00', '30.00', AppointmentStatus::Cancelled, null, ['customer_name' => 'Segundo Cliente']);
        $this->make('2026-11-04', '08:00', '50.00', AppointmentStatus::Confirmed, null, ['customer_name' => 'Outro Dia']);

        $this->actingAs($this->admin())->get('/admin/agenda?date=2026-11-03')
            ->assertOk()
            ->assertSeeInOrder(['Primeiro Cliente', 'Segundo Cliente', 'Terceiro Cliente'])
            ->assertSee('11911112222')->assertSee('Confirmado')->assertSee('Cancelado')
            ->assertDontSee('Outro Dia');
    }

    public function test_agenda_vazia_mostra_mensagem(): void
    {
        $this->actingAs($this->admin())->get('/admin/agenda?date=2026-12-01')->assertOk()->assertSee('Nenhum agendamento nesta data');
    }

    public function test_agenda_usa_hoje_por_padrao(): void
    {
        $this->make('2026-11-02', '10:00', '50.00', AppointmentStatus::Confirmed, null, ['customer_name' => 'Cliente de Hoje']);
        $this->actingAs($this->admin())->get('/admin/agenda')->assertSee('Cliente de Hoje');
    }

    // --- Conclusão ----------------------------------------------------------------

    public function test_admin_conclui_atendimento_apos_o_inicio(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:45', 'America/Sao_Paulo'));

        $this->actingAs($this->admin())->post(route('admin.appointments.complete', $a))->assertSessionHas('success');

        $a->refresh();
        $this->assertSame(AppointmentStatus::Completed, $a->status);
        $this->assertNotNull($a->completed_at);
        $this->assertDatabaseHas('appointment_histories', ['appointment_id' => $a->id, 'action' => 'completed']);
    }

    public function test_nao_conclui_atendimento_futuro_cancelado_ou_ja_concluido(): void
    {
        $admin = $this->admin();
        $futuro = $this->book($this->service(), '2026-11-03', '10:00');
        $cancelado = $this->make('2026-11-01', '10:00', '50.00', AppointmentStatus::Cancelled);
        $concluido = $this->make('2026-11-01', '11:00', '50.00', AppointmentStatus::Completed);

        $this->actingAs($admin)->post(route('admin.appointments.complete', $futuro))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.appointments.complete', $cancelado))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.appointments.complete', $concluido))->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Confirmed, $futuro->fresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $cancelado->fresh()->status);
    }

    public function test_concluir_nao_e_possivel_apos_cancelar_pelo_servico(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        app(BookingService::class)->cancel($a);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00', 'America/Sao_Paulo'));

        $this->expectException(BookingException::class);
        app(BookingService::class)->complete($a);
    }

    // --- Faturamento ----------------------------------------------------------------

    private function seedDay(): void
    {
        $this->make('2026-11-03', '09:00', '50.00', AppointmentStatus::Completed);
        $this->make('2026-11-03', '10:00', '70.00', AppointmentStatus::Completed);
        $this->make('2026-11-03', '11:00', '30.00', AppointmentStatus::Confirmed);
        $this->make('2026-11-03', '12:00', '25.50', AppointmentStatus::Confirmed);
        $this->make('2026-11-03', '13:00', '50.00', AppointmentStatus::Cancelled);
        // Outro dia: nunca entra nos totais de 03/11.
        $this->make('2026-11-04', '09:00', '500.00', AppointmentStatus::Completed);
    }

    public function test_relatorio_calcula_realizado_previsto_cancelamentos_e_ticket_medio(): void
    {
        $this->seedDay();
        $r = app(RevenueReport::class)->forDate('2026-11-03');

        $this->assertSame(5, $r['total']);
        $this->assertSame(2, $r['completed']);
        $this->assertSame(2, $r['confirmed']);
        $this->assertSame(1, $r['cancelled']);
        $this->assertSame('120.00', $r['realized']);   // 50 + 70, cancelado e confirmado fora
        $this->assertSame('55.50', $r['forecast']);    // 30 + 25,50; cancelado fora
        $this->assertSame('60.00', $r['average']);     // 120 / 2
        $this->assertCount(5, $r['appointments']);
    }

    public function test_pagina_do_relatorio_formata_em_reais_e_filtra_por_data(): void
    {
        $this->seedDay();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/faturamento?date=2026-11-03')
            ->assertOk()->assertSee('R$ 120,00')->assertSee('R$ 55,50')->assertSee('R$ 60,00')->assertDontSee('R$ 500,00');

        $this->actingAs($admin)->get('/admin/faturamento?date=2026-11-04')
            ->assertOk()->assertSee('R$ 500,00')->assertDontSee('R$ 120,00');
    }

    public function test_relatorio_sem_dados_mostra_mensagem_e_sem_ticket_medio(): void
    {
        $r = app(RevenueReport::class)->forDate('2026-12-15');
        $this->assertSame(0, $r['total']);
        $this->assertSame('0.00', $r['realized']);
        $this->assertNull($r['average']);

        $this->actingAs($this->admin())->get('/admin/faturamento?date=2026-12-15')->assertSee('Nenhum agendamento nesta data');
    }

    public function test_ticket_medio_e_arredondado_em_duas_casas(): void
    {
        $this->make('2026-11-03', '09:00', '33.33', AppointmentStatus::Completed);
        $this->make('2026-11-03', '10:00', '33.33', AppointmentStatus::Completed);
        $this->make('2026-11-03', '11:00', '33.34', AppointmentStatus::Completed);

        $r = app(RevenueReport::class)->forDate('2026-11-03');
        $this->assertSame('100.00', $r['realized']);
        $this->assertSame('33.33', $r['average']);
    }

    public function test_relatorio_usa_preco_historico_e_nao_o_atual_do_catalogo(): void
    {
        $service = $this->service(40, '50.00');
        $a = $this->book($service, '2026-11-03', '10:00');
        $service->update(['price' => '200.00']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00', 'America/Sao_Paulo'));
        app(BookingService::class)->complete($a);

        $r = app(RevenueReport::class)->forDate('2026-11-03');
        $this->assertSame('50.00', $r['realized']);
        $this->assertSame('50.00', $r['average']);
    }

    public function test_cancelamento_e_contado_pela_data_original_e_remarcado_nao_conta_duas_vezes(): void
    {
        $service = $this->service();
        $cancelado = $this->book($service, '2026-11-03', '10:00');
        $remarcado = $this->book($service, '2026-11-03', '14:00');
        app(BookingService::class)->cancel($cancelado);
        app(BookingService::class)->reschedule($remarcado, '2026-11-05', '14:00');

        $d3 = app(RevenueReport::class)->forDate('2026-11-03');
        $d5 = app(RevenueReport::class)->forDate('2026-11-05');

        $this->assertSame(1, $d3['total']);
        $this->assertSame(1, $d3['cancelled']);
        $this->assertSame('0.00', $d3['forecast']);   // cancelado não entra na previsão
        $this->assertSame(1, $d5['total']);            // o remarcado só existe na nova data
        $this->assertSame('50.00', $d5['forecast']);
    }

    public function test_relatorio_nao_afirma_pagamento_para_confirmados(): void
    {
        $this->make('2026-11-03', '11:00', '30.00', AppointmentStatus::Confirmed);
        $r = app(RevenueReport::class)->forDate('2026-11-03');

        $this->assertSame('0.00', $r['realized']);  // confirmado não é faturamento realizado
        $this->assertSame('30.00', $r['forecast']);
    }
}
