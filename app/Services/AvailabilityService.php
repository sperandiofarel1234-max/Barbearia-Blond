<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Disponibilidade e detecção de conflitos.
 *
 * A regra de sobreposição existe em um único lugar (overlaps) e é usada tanto
 * para listar horários quanto para validar criação/remarcação dentro do lock do dia.
 */
class AvailabilityService
{
    public function __construct(private BusinessHours $hours) {}

    /**
     * inicio_existente < fim_novo E fim_existente > inicio_novo
     * Horários em "H:i:s" (comparação lexicográfica correta por serem zero-padded).
     * Atendimentos encostados (fim == início) NÃO conflitam.
     */
    public static function overlaps(string $existingStart, string $existingEnd, string $newStart, string $newEnd): bool
    {
        return $existingStart < $newEnd && $existingEnd > $newStart;
    }

    /**
     * Horários ("H:i") livres para um serviço de $minutes na data.
     * $ignoreAppointmentId permite remarcar sem conflitar consigo mesmo.
     *
     * @return list<string>
     */
    public function slots(int $minutes, CarbonImmutable $date, ?int $ignoreAppointmentId = null): array
    {
        $occupied = $this->occupied($date->format('Y-m-d'), $ignoreAppointmentId);
        $now = $this->hours->now();
        $slots = [];

        foreach ($this->hours->candidateStarts($date, $minutes) as $start) {
            if ($start->lessThanOrEqualTo($now)) {
                continue;
            }
            if (! $this->conflicts($occupied, $start->format('H:i:s'), $start->addMinutes($minutes)->format('H:i:s'))) {
                $slots[] = $start->format('H:i');
            }
        }

        return $slots;
    }

    public function hasConflict(string $date, CarbonImmutable $start, int $minutes, ?int $ignoreAppointmentId = null): bool
    {
        return $this->conflicts(
            $this->occupied($date, $ignoreAppointmentId),
            $start->format('H:i:s'),
            $start->addMinutes($minutes)->format('H:i:s'),
        );
    }

    /** @return list<array{string,string}> */
    private function occupied(string $date, ?int $ignoreAppointmentId): array
    {
        return Appointment::query()
            ->occupying()
            ->onDate($date)
            ->when($ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $ignoreAppointmentId))
            ->get(['start_time', 'end_time'])
            ->map(fn ($a) => [$a->start_time, $a->end_time])
            ->all();
    }

    /** @param list<array{string,string}> $occupied */
    private function conflicts(array $occupied, string $start, string $end): bool
    {
        foreach ($occupied as [$existingStart, $existingEnd]) {
            if (self::overlaps($existingStart, $existingEnd, $start, $end)) {
                return true;
            }
        }

        return false;
    }
}
