<?php

/*
|--------------------------------------------------------------------------
| Regras de negócio da barbearia (fonte única)
|--------------------------------------------------------------------------
| Todas as regras de horário, política de cancelamento e lembretes vivem aqui
| e são consumidas por App\Services\BusinessHours / ReminderService.
*/
return [
    'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),

    // Segunda (1) a sábado (6), conforme Carbon::dayOfWeekIso. Domingo (7) fechado.
    'open_weekdays' => [1, 2, 3, 4, 5, 6],
    'opening_time' => '09:00',
    'closing_time' => '18:00',

    // Intervalo entre inícios de horário apresentados ao cliente.
    'slot_step_minutes' => 10,

    // Política: cancelar/remarcar somente com no mínimo N horas de antecedência.
    'cancel_min_hours' => (int) env('BARBEARIA_CANCEL_MIN_HOURS', 2),

    // Lembretes: tipo => minutos de antecedência.
    'reminders' => [
        '24h' => 24 * 60,
        '2h' => 2 * 60,
    ],
    'reminder_max_attempts' => 3,
    'reminder_retry_minutes' => 5,
];
