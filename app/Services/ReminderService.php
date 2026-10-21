<?php

namespace App\Services;

use App\Enums\ReminderStatus;
use App\Jobs\SendReminderJob;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Programa, despacha e envia lembretes (24h e 2h antes, por padrão).
 *
 * Estados: pending → sent | failed | cancelled.
 * Uma linha por (agendamento, tipo) — a restrição unique impede duplicidade por construção.
 */
class ReminderService
{
    public function __construct(private BusinessHours $hours) {}

    /**
     * Recalcula os lembretes de um agendamento (criação ou remarcação).
     * Só programa o que ainda está no futuro.
     */
    public function sync(Appointment $appointment): void
    {
        if (! $appointment->isConfirmed()) {
            $this->cancelPending($appointment);

            return;
        }

        $now = $this->hours->now();

        foreach (config('barbearia.reminders') as $type => $minutesBefore) {
            $remindAt = $appointment->startsAt()->subMinutes($minutesBefore);
            $existing = AppointmentReminder::where(['appointment_id' => $appointment->id, 'type' => $type])->first();

            if ($remindAt->lessThanOrEqualTo($now)) {
                // Horário do lembrete já passou: não programar; invalida um pendente antigo.
                if ($existing && $existing->status === ReminderStatus::Pending) {
                    $existing->forceFill(['status' => ReminderStatus::Cancelled])->save();
                }

                continue;
            }

            $reminder = $existing ?? new AppointmentReminder;
            $reminder->forceFill([
                'appointment_id' => $appointment->id,
                'type' => $type,
                'remind_at' => $remindAt,
                'status' => ReminderStatus::Pending,
                'attempts' => 0,
                'sent_at' => null,
                'last_error' => null,
            ])->save();
        }
    }

    /** Invalida lembretes futuros ainda não enviados. */
    public function cancelPending(Appointment $appointment): void
    {
        AppointmentReminder::query()
            ->where('appointment_id', $appointment->id)
            ->where('status', ReminderStatus::Pending->value)
            ->update(['status' => ReminderStatus::Cancelled->value, 'updated_at' => now()]);
    }

    /** Enfileira um job por lembrete vencido (chamado pelo Scheduler). */
    public function dispatchDue(): int
    {
        $ids = AppointmentReminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->where('remind_at', '<=', $this->hours->now())
            ->orderBy('remind_at')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            SendReminderJob::dispatch($id);
        }

        return $ids->count();
    }

    /**
     * Envia um lembrete. Idempotente: a linha é travada e só envia se ainda estiver pendente.
     */
    public function send(int $reminderId): void
    {
        DB::transaction(function () use ($reminderId) {
            $reminder = AppointmentReminder::query()->whereKey($reminderId)->lockForUpdate()->first();

            if (! $reminder || $reminder->status !== ReminderStatus::Pending) {
                return; // já enviado/cancelado/falho: evita envio duplicado
            }

            $appointment = Appointment::with('service')->find($reminder->appointment_id);

            if (! $appointment || ! $appointment->isConfirmed() || $appointment->startsAt()->lessThanOrEqualTo($this->hours->now())) {
                $reminder->forceFill(['status' => ReminderStatus::Cancelled])->save();

                return;
            }

            if ($reminder->remind_at->greaterThan($this->hours->now())) {
                return; // reprogramado depois do despacho
            }

            $reminder->attempts++;

            try {
                Notification::route('mail', $appointment->customer_email)
                    ->notifyNow(new AppointmentReminderNotification($appointment, $reminder->type));

                $reminder->forceFill([
                    'status' => ReminderStatus::Sent,
                    'sent_at' => $this->hours->now(),
                    'last_error' => null,
                ]);
            } catch (Throwable $e) {
                $reminder->last_error = $this->safeError($e, $appointment);

                if ($reminder->attempts >= (int) config('barbearia.reminder_max_attempts')) {
                    $reminder->status = ReminderStatus::Failed;
                } else {
                    // Nova tentativa mais tarde, pelo próximo ciclo do Scheduler.
                    $reminder->remind_at = $this->hours->now()->addMinutes((int) config('barbearia.reminder_retry_minutes'));
                }
            }

            $reminder->save();
        });
    }

    /** Mensagem de erro curta, sem e-mail/telefone/token do cliente. */
    private function safeError(Throwable $e, Appointment $appointment): string
    {
        $message = str_replace(
            array_filter([$appointment->customer_email, $appointment->customer_phone, $appointment->manage_token]),
            '[oculto]',
            $e->getMessage(),
        );

        return Str::limit(class_basename($e).': '.$message, 250);
    }
}
