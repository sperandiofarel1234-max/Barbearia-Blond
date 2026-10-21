<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_cadastra_servico_valido(): void
    {
        $this->actingAs($this->admin())->post('/admin/servicos', [
            'name' => 'Pigmentação', 'description' => 'Barba', 'duration_minutes' => 30, 'price' => '45,50', 'active' => 1,
        ])->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', ['name' => 'Pigmentação', 'duration_minutes' => 30, 'price' => '45.50', 'active' => 1]);
    }

    public function test_valida_nome_duracao_e_preco(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/servicos', ['name' => '', 'duration_minutes' => 30, 'price' => '10'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'X', 'duration_minutes' => 0, 'price' => '10'])->assertSessionHasErrors('duration_minutes');
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'X', 'duration_minutes' => -5, 'price' => '10'])->assertSessionHasErrors('duration_minutes');
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'X', 'duration_minutes' => 'abc', 'price' => '10'])->assertSessionHasErrors('duration_minutes');
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'X', 'duration_minutes' => 30, 'price' => '-1'])->assertSessionHasErrors('price');
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'X', 'duration_minutes' => 30, 'price' => ''])->assertSessionHasErrors('price');

        // Preço zero é permitido (não pode ser negativo).
        $this->actingAs($admin)->post('/admin/servicos', ['name' => 'Cortesia', 'duration_minutes' => 10, 'price' => '0'])->assertSessionHasNoErrors();
    }

    public function test_desativar_servico_preserva_historico_e_bloqueia_novos_agendamentos(): void
    {
        $service = $this->service();
        $appointment = $this->book($service, '2026-11-03', '10:00');

        $this->actingAs($this->admin())->patch(route('admin.services.toggle', $service))->assertRedirect();

        $this->assertFalse($service->fresh()->active);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]); // histórico intacto

        // Pela rota pública
        $this->post(route('booking.store', $service), $this->customer(['date' => '2026-11-03', 'time' => '11:00']))
            ->assertRedirect(route('home'))->assertSessionHas('error');
        $this->assertSame(1, Appointment::count());
        $this->get(route('booking.create', $service))->assertNotFound();
        // E no serviço de domínio (defesa em profundidade)
        $this->expectException(\App\Exceptions\BookingException::class);
        $this->book($service, '2026-11-03', '11:00');
    }

    public function test_servico_inativo_nao_aparece_na_home(): void
    {
        $this->service();
        Service::factory()->inactive()->create(['name' => 'Servico Oculto']);

        $this->get('/')->assertOk()->assertDontSee('Servico Oculto');
    }

    public function test_alterar_preco_nao_muda_agendamentos_antigos(): void
    {
        $service = $this->service(40, '50.00');
        $appointment = $this->book($service, '2026-11-03', '10:00');

        $this->actingAs($this->admin())->put(route('admin.services.update', $service), [
            'name' => $service->name, 'duration_minutes' => 60, 'price' => '99,90', 'active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('99.90', $service->fresh()->price);
        $appointment->refresh();
        $this->assertSame('50.00', $appointment->price);
        $this->assertSame(40, $appointment->duration_minutes);
        $this->assertSame('10:40:00', $appointment->end_time);
    }

    public function test_area_de_servicos_exige_administrador(): void
    {
        $this->get('/admin/servicos')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/servicos')->assertForbidden();
    }
}
