<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras de funcionamento e política de antecedência.
 * Reutilizado por disponibilidade, criação, remarcação e cancelamento.
 */
class BusinessHours
{
    public function timezone(): string
    {
        return config('barbearia.timezone');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** Interpreta "Y-m-d" de forma estrita; null se inválida. */
    public function parseDate(?string $date): ?CarbonImmutable
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());

        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /** Combina data e "H:i" em um instante; null se inválido. */
    public function parseStart(CarbonImmutable $date, ?string $time): ?CarbonImmutable
    {
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date->format('Y-m-d').' '.$time, $this->timezone());
    }

    public function isOpenDay(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, config('barbearia.open_weekdays'), true);
    }

    public function opensAt(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d').' '.config('barbearia.opening_time'), $this->timezone());
    }

    public function closesAt(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d').' '.config('barbearia.closing_time'), $this->timezone());
    }

    /**
     * Valida se um atendimento começando em $start e durando $minutes pode existir.
     * Não verifica conflitos com outros agendamentos (ver AvailabilityService).
     *
     * @throws BookingException
     */
    public function assertBookable(CarbonImmutable $start, int $minutes): void
    {
        if ($start->lessThanOrEqualTo($this->now())) {
            throw new BookingException('Não é possível agendar em um horário que já passou.');
        }

        if (! $this->isOpenDay($start)) {
            throw new BookingException('A barbearia não atende aos domingos. Escolha outro dia.');
        }

        $opens = $this->opensAt($start);
        $closes = $this->closesAt($start);
        $end = $start->addMinutes($minutes);

        if ($start->lessThan($opens)) {
            throw new BookingException('O atendimento deve começar a partir das '.$opens->format('H:i').'.');
        }

        if ($end->greaterThan($closes)) {
            throw new BookingException(sprintf(
                'O atendimento terminaria às %s, depois do fechamento (%s).',
                $end->format('H:i'),
                $closes->format('H:i'),
            ));
        }

        $step = (int) config('barbearia.slot_step_minutes');
        if ($opens->diffInMinutes($start) % $step !== 0) {
            throw new BookingException("Escolha um dos horários disponíveis (intervalos de {$step} minutos).");
        }
    }

    /**
     * Inícios possíveis no dia (grade de 10 em 10 minutos) em que o serviço cabe antes do fechamento.
     *
     * @return list<CarbonImmutable>
     */
    public function candidateStarts(CarbonImmutable $date, int $minutes): array
    {
        if (! $this->isOpenDay($date)) {
            return [];
        }

        $step = (int) config('barbearia.slot_step_minutes');
        $closes = $this->closesAt($date);
        $starts = [];

        for ($t = $this->opensAt($date); $t->addMinutes($minutes)->lessThanOrEqualTo($closes); $t = $t->addMinutes($step)) {
            $starts[] = $t;
        }

        return $starts;
    }

    // --- Política de cancelamento / remarcação -------------------------------

    public function minHoursToChange(): int
    {
        return (int) config('barbearia.cancel_min_hours');
    }

    /** Último instante em que o cliente ainda pode cancelar/remarcar. */
    public function changeDeadline(Appointment $appointment): CarbonImmutable
    {
        return $appointment->startsAt()->subHours($this->minHoursToChange());
    }

    public function canChange(Appointment $appointment): bool
    {
        return $appointment->isConfirmed() && $this->now()->lessThanOrEqualTo($this->changeDeadline($appointment));
    }

    /**
     * @throws BookingException
     */
    public function assertCanChange(Appointment $appointment, string $verb): void
    {
        if (! $appointment->isConfirmed()) {
            throw new BookingException(sprintf(
                'Este agendamento está %s e não pode ser %s.',
                mb_strtolower($appointment->status->label()),
                $verb === 'cancelar' ? 'cancelado' : 'remarcado',
            ));
        }

        if ($this->now()->greaterThan($this->changeDeadline($appointment))) {
            throw new BookingException(sprintf(
                'Só é possível %s com pelo menos %d hora(s) de antecedência. Entre em contato com a barbearia.',
                $verb,
                $this->minHoursToChange(),
            ));
        }
    }
}
