<?php

namespace App\Enums;

/**
 * Ciclo de vida do agendamento. Única fonte das transições permitidas:
 *
 *   confirmed ──► cancelled   (terminal)
 *   confirmed ──► completed   (terminal)
 */
enum AppointmentStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
            self::Completed => 'Concluído',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Confirmed => [self::Cancelled, self::Completed],
            self::Cancelled, self::Completed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Cancelados liberam o horário; os demais ocupam a agenda. */
    public function occupiesAgenda(): bool
    {
        return $this !== self::Cancelled;
    }
}
