<?php

namespace App\Support;

class Money
{
    /** Formata valor decimal (string do banco) no padrão brasileiro: R$ 1.234,50 */
    public static function brl(string|int|float|null $value): string
    {
        return 'R$ '.number_format((float) ($value ?? 0), 2, ',', '.');
    }
}
