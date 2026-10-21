<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Sent => 'Enviado',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }
}
