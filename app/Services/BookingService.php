<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Service;
use App\Notifications\AppointmentConfirmedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Todas as operações que criam ou alteram agendamentos passam por aqui.
 *
 * ESTRATÉGIA DE CONCORRÊNCIA (MySQL/InnoDB)
 * Travar linhas de agendamentos existentes não impede duas inserções simultâneas
 * quando não há registro no horário. Por isso cada data tem uma linha em
 * `day_locks`; toda operação que ocupa horário em uma data faz
 * SELECT ... FOR UPDATE nessa linha dentro de uma transação. As operações no mesmo
 * dia são serializadas: a segunda só confere conflitos depois do commit da primeira
 * e, então, enxerga o agendamento recém-criado.
 *
 * Ordem de travas (evita deadlock): agendamento → dia. Criar só trava o dia.
 */
class BookingService
{
    public function __construct(
        private BusinessHours $hours,
        private AvailabilityService $availability,
        private ReminderService $reminders,
    ) {
    }

    /**
     * @param  array{customer_name:string,customer_phone:string,customer_email:string}  $customer
     *
     * @throws BookingException
     */
    public function create(Service $service, array $customer, string $date, string $time): Appointment
    {
        $day = $this->hours->parseDate($date);
        $start = $day ? $this->hours->parseStart($day, $time) : null;
        if (! $start) {
            throw new BookingException('Data ou horário inválido.');
        }

        $this->ensureDayLockExists($date);

        $appointment = DB::transaction(function () use ($service, $customer, $date, $start) {
            $this->lockDay($date);

            // Dados do serviço são lidos do banco dentro do lock: nada vem do navegador.
            $service = Service::query()->whereKey($service->getKey())->sharedLock()->first();
            if (! $service || ! $service->active) {
                throw new BookingException('Este serviço não está disponível para agendamento.');
            }

            $this->hours->assertBookable($start, $service->duration_minutes);
            $this->assertNoConflict($date, $start, $service->duration_minutes);

            $token = Appointment::generateToken();
            $appointment = new Appointment($customer);
            $appointment->forceFill([
                'service_id' => $service->id,
                'date' => $date,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->addMinutes($service->duration_minutes)->format('H:i:s'),
                'duration_minutes' => $service->duration_minutes, // snapshot
                'price' => $service->price,                        // snapshot histórico
                'status' => AppointmentStatus::Confirmed,
                'manage_token_hash' => Appointment::hashToken($token),
                'manage_token' => $token,
            ])->save();

            $this->history($appointment, 'created', null, null, $date, $start->format('H:i:s'));
            $this->reminders->sync($appointment);

            return $appointment;
        }, 3);

        $this->notify($appointment, false);

        return $appointment;
    }

    /**
     * Remarcação atômica: se qualquer validação falhar, o agendamento original permanece intacto.
     *
     * @throws BookingException
     */
    public function reschedule(Appointment $appointment, string $date, string $time): Appointment
    {
        $day = $this->hours->parseDate($date);
        $start = $day ? $this->hours->parseStart($day, $time) : null;
        if (! $start) {
            throw new BookingException('Data ou horário inválido.');
        }

        $this->ensureDayLockExists($date);

        $appointment = DB::transaction(function () use ($appointment, $date, $start) {
            $current = $this->lockAppointment($appointment);
            $this->hours->assertCanChange($current, 'remarcar');

            $this->lockDay($date);

            $this->hours->assertBookable($start, $current->duration_minutes);

            if ($current->dateString() === $date && $current->start_time === $start->format('H:i:s')) {
                throw new BookingException('Escolha uma data ou horário diferente do atual.');
            }

            $this->assertNoConflict($date, $start, $current->duration_minutes, $current->id);

            $oldDate = $current->dateString();
            $oldStart = $current->start_time;

            $current->forceFill([
                'date' => $date,
                'start_time' => $start->format('H:i:s'),
                // Duração e preço contratados originalmente são preservados.
                'end_time' => $start->addMinutes($current->duration_minutes)->format('H:i:s'),
            ])->save();

            $this->history($current, 'rescheduled', $oldDate, $oldStart, $date, $start->format('H:i:s'));
            $this->reminders->sync($current);

            return $current;
        }, 3);

        $this->notify($appointment, true);

        return $appointment;
    }

    /**
     * @throws BookingException
     */
    public function cancel(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $current = $this->lockAppointment($appointment);
            $this->hours->assertCanChange($current, 'cancelar');

            $current->transitionTo(AppointmentStatus::Cancelled);
            $current->cancelled_at = $this->hours->now();
            $current->save();

            $this->history($current, 'cancelled', $current->dateString(), $current->start_time, null, null);
            $this->reminders->cancelPending($current);

            return $current;
        }, 3);
    }

    /**
     * Ação administrativa: marca o atendimento como concluído.
     * Só é possível depois do horário de início e a partir de "confirmado".
     *
     * @throws BookingException
     */
    public function complete(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $current = $this->lockAppointment($appointment);

            if ($current->isConfirmed() && $current->startsAt()->greaterThan($this->hours->now())) {
                throw new BookingException('Só é possível concluir um atendimento depois do horário de início.');
            }

            $current->transitionTo(AppointmentStatus::Completed);
            $current->completed_at = $this->hours->now();
            $current->save();

            $this->history($current, 'completed', null, null, $current->dateString(), $current->start_time);
            $this->reminders->cancelPending($current);

            return $current;
        }, 3);
    }

    // --- Internos -------------------------------------------------------------

    /** Cria a linha de controle da data fora da transação (evita travas compartilhadas e deadlocks). */
    private function ensureDayLockExists(string $date): void
    {
        DB::table('day_locks')->insertOrIgnore(['date' => $date]);
    }

    private function lockDay(string $date): void
    {
        DB::table('day_locks')->where('date', $date)->lockForUpdate()->first();
    }

    private function lockAppointment(Appointment $appointment): Appointment
    {
        return Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertNoConflict(string $date, $start, int $minutes, ?int $ignoreId = null): void
    {
        if ($this->availability->hasConflict($date, $start, $minutes, $ignoreId)) {
            throw new BookingException('Este horário acabou de ser ocupado. Escolha outro horário disponível.');
        }
    }

    private function history(Appointment $a, string $action, ?string $oldDate, ?string $oldStart, ?string $newDate, ?string $newStart): void
    {
        (new AppointmentHistory)->forceFill([
            'appointment_id' => $a->id,
            'action' => $action,
            'old_date' => $oldDate,
            'old_start_time' => $oldStart,
            'new_date' => $newDate,
            'new_start_time' => $newStart,
        ])->save();
    }

    /** Falha de e-mail nunca derruba o agendamento; nada sensível é registrado. */
    private function notify(Appointment $appointment, bool $rescheduled): void
    {
        try {
            Notification::route('mail', $appointment->customer_email)
                ->notifyNow(new AppointmentConfirmedNotification($appointment, $rescheduled));
        } catch (Throwable $e) {
            Log::warning('Falha ao enviar confirmação de agendamento.', [
                'appointment_id' => $appointment->id,
                'exception' => class_basename($e),
            ]);
        }
    }
}
