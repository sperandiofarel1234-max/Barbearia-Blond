<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ReminderStatus;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Notifications\AppointmentReminderNotification;
use App\Services\BookingService;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $dt): CarbonImmutable
    {
        return CarbonImmutable::parse($dt, 'America/Sao_Paulo');
    }

    private function remindAt(Appointment $a, string $type): string
    {
        return $a->reminders()->where('type', $type)->firstOrFail()->remind_at->format('Y-m-d H:i');
    }

    public function test_cria_lembretes_de_24h_e_2h_quando_ha_tempo(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00'); // agora 02/11 08:00

        $this->assertSame(2, $a->reminders()->count());
        $this->assertSame('2026-11-02 10:00', $this->remindAt($a, '24h'));
        $this->assertSame('2026-11-03 08:00', $this->remindAt($a, '2h'));
        $this->assertSame(2, $a->reminders()->where('status', ReminderStatus::Pending->value)->count());
    }

    public function test_com_menos_de_24h_programa_apenas_o_de_2h(): void
    {
        $a = $this->book($this->service(), '2026-11-02', '12:00'); // 4h à frente

        $this->assertSame(['2h'], $a->reminders()->pluck('type')->all());
        $this->assertSame('2026-11-02 10:00', $this->remindAt($a, '2h'));
    }

    public function test_nao_cria_lembretes_para_horarios_ja_passados(): void
    {
        $limite = $this->book($this->service(), '2026-11-02', '10:00'); // 2h exatas: lembrete seria "agora"
        $curto = $this->book($this->service(20), '2026-11-02', '09:00'); // 1h

        $this->assertSame(0, $limite->reminders()->count());
        $this->assertSame(0, $curto->reminders()->count());
    }

    public function test_scheduler_processa_lembretes_vencidos_e_marca_como_enviado(): void
    {
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');

        // Antes do horário do lembrete nada é enfileirado.
        Artisan::call('reminders:dispatch');
        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 0); // (a confirmação de agendamento é outra notificação)

        $this->travelTo($this->at('2026-11-02 10:00:30'));
        Artisan::call('reminders:dispatch'); // fila "sync" nos testes: o job roda na hora

        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 1);
        $r = $a->reminders()->where('type', '24h')->first();
        $this->assertSame(ReminderStatus::Sent, $r->status);
        $this->assertSame(1, $r->attempts);
        $this->assertNotNull($r->sent_at);
        $this->assertSame(ReminderStatus::Pending, $a->reminders()->where('type', '2h')->first()->status);
    }

    public function test_nao_envia_duplicado_mesmo_com_varias_execucoes(): void
    {
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->travelTo($this->at('2026-11-02 10:05'));
        $reminder = $a->reminders()->where('type', '24h')->first();

        Artisan::call('reminders:dispatch');
        Artisan::call('reminders:dispatch');
        app(ReminderService::class)->send($reminder->id);
        app(ReminderService::class)->send($reminder->id);

        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 1);
    }

    public function test_nao_envia_lembrete_de_agendamento_cancelado(): void
    {
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        app(BookingService::class)->cancel($a);

        $this->travelTo($this->at('2026-11-02 10:30'));
        Artisan::call('reminders:dispatch');

        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 0); // (a confirmação de agendamento é outra notificação)
        $this->assertSame(0, $a->reminders()->where('status', 'pending')->count());
    }

    public function test_cancelamento_direto_no_banco_tambem_nao_gera_envio(): void
    {
        // Defesa em profundidade: o job reconfere o status do agendamento antes de enviar.
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $a->forceFill(['status' => AppointmentStatus::Cancelled])->save();

        $this->travelTo($this->at('2026-11-02 10:30'));
        Artisan::call('reminders:dispatch');

        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 0); // (a confirmação de agendamento é outra notificação)
        $this->assertSame(ReminderStatus::Cancelled, $a->reminders()->where('type', '24h')->first()->status);
    }

    public function test_remarcacao_recalcula_lembretes_sem_duplicar(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00');

        app(BookingService::class)->reschedule($a, '2026-11-05', '11:00');

        $this->assertSame(2, $a->reminders()->count());
        $this->assertSame('2026-11-04 11:00', $this->remindAt($a, '24h'));
        $this->assertSame('2026-11-05 09:00', $this->remindAt($a, '2h'));
        $this->assertSame(2, $a->reminders()->where('status', 'pending')->count());
    }

    public function test_remarcacao_para_perto_invalida_o_lembrete_que_ja_passou(): void
    {
        $a = $this->book($this->service(), '2026-11-04', '14:00'); // 24h em 03/11 14:00; 2h em 04/11 12:00
        app(BookingService::class)->reschedule($a, '2026-11-02', '15:00'); // hoje às 15:00: 24h já passou

        $this->assertSame(ReminderStatus::Cancelled, $a->reminders()->where('type', '24h')->first()->status);
        $this->assertSame(ReminderStatus::Pending, $a->reminders()->where('type', '2h')->first()->status);
        $this->assertSame('2026-11-02 13:00', $this->remindAt($a, '2h'));
    }

    public function test_remarcacao_reativa_lembrete_ja_enviado_para_o_novo_horario(): void
    {
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->travelTo($this->at('2026-11-02 10:05'));
        Artisan::call('reminders:dispatch'); // enviou o 24h do horário antigo

        app(BookingService::class)->reschedule($a, '2026-11-06', '10:00');

        $r = $a->reminders()->where('type', '24h')->first();
        $this->assertSame(ReminderStatus::Pending, $r->status); // volta a pendente para o novo horário
        $this->assertSame('2026-11-05 10:00', $r->remind_at->format('Y-m-d H:i'));
        $this->assertNull($r->sent_at);
    }

    public function test_falha_no_envio_registra_tentativa_erro_sem_dados_sensiveis_e_vira_failed(): void
    {
        $a = $this->book($this->service(), '2026-11-03', '10:00', ['customer_email' => 'ana@example.com']);

        Mail::extend('falhando', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Conexão recusada ao enviar para ana@example.com');
            }

            public function __toString(): string
            {
                return 'falhando';
            }
        });
        config(['mail.mailers.falhando' => ['transport' => 'falhando'], 'mail.default' => 'falhando']);
        Mail::purge();

        $reminder = $a->reminders()->where('type', '24h')->first();
        $this->travelTo($this->at('2026-11-02 10:01'));
        Artisan::call('reminders:dispatch');

        $reminder->refresh();
        $this->assertSame(ReminderStatus::Pending, $reminder->status); // ainda tem tentativas
        $this->assertSame(1, $reminder->attempts);
        $this->assertNotNull($reminder->last_error);
        $this->assertStringNotContainsString('ana@example.com', $reminder->last_error);
        $this->assertNull($reminder->sent_at);
        $this->assertSame('2026-11-02 10:06', $reminder->remind_at->format('Y-m-d H:i')); // nova tentativa em 5 min

        $this->travelTo($this->at('2026-11-02 10:07'));
        Artisan::call('reminders:dispatch');
        $this->travelTo($this->at('2026-11-02 10:13'));
        Artisan::call('reminders:dispatch');

        $reminder->refresh();
        $this->assertSame(ReminderStatus::Failed, $reminder->status);
        $this->assertSame(3, $reminder->attempts);

        // Falhado não é reenviado.
        $this->travelTo($this->at('2026-11-02 11:00'));
        Artisan::call('reminders:dispatch');
        $this->assertSame(3, $reminder->fresh()->attempts);
    }

    public function test_falha_no_email_de_confirmacao_nao_impede_o_agendamento(): void
    {
        Mail::extend('falhando2', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP indisponível');
            }

            public function __toString(): string
            {
                return 'falhando2';
            }
        });
        config(['mail.mailers.falhando2' => ['transport' => 'falhando2'], 'mail.default' => 'falhando2']);
        Mail::purge();

        $a = $this->book($this->service(), '2026-11-03', '10:00');

        $this->assertDatabaseHas('appointments', ['id' => $a->id, 'status' => 'confirmed']);
    }

    public function test_conteudo_do_lembrete_tem_cliente_servico_data_horario_e_link_de_gerenciamento(): void
    {
        $service = $this->service(40);
        $a = $this->book($service, '2026-11-03', '10:00', ['customer_name' => 'Ana Souza']);

        $html = (new AppointmentReminderNotification($a, '24h'))->toMail($a)->render()->toHtml();

        $this->assertStringContainsString('Ana Souza', $html);
        $this->assertStringContainsString($service->name, $html);
        $this->assertStringContainsString('03/11/2026', $html);
        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString(route('appointments.manage', $a->manage_token), $html);
        $this->assertStringNotContainsString($a->customer_phone, $html); // sem dados sensíveis extras
    }

    public function test_funciona_sem_smtp_com_transporte_de_teste(): void
    {
        // phpunit.xml usa MAIL_MAILER=array: nenhum e-mail real é enviado.
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->travelTo($this->at('2026-11-02 10:10'));
        Artisan::call('reminders:dispatch');

        $this->assertSame(ReminderStatus::Sent, $a->reminders()->where('type', '24h')->first()->status);
        $this->assertSame('array', config('mail.default'));
    }

    public function test_lembrete_de_atendimento_que_ja_comecou_e_cancelado_e_nao_enviado(): void
    {
        Notification::fake();
        $a = $this->book($this->service(), '2026-11-03', '10:00');
        $this->travelTo($this->at('2026-11-03 10:30')); // worker ficou parado até depois do atendimento
        Artisan::call('reminders:dispatch');

        Notification::assertSentOnDemandTimes(AppointmentReminderNotification::class, 0); // (a confirmação de agendamento é outra notificação)
        $this->assertSame(0, AppointmentReminder::where('status', 'pending')->count());
    }
}
