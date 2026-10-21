<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Enfileira o envio dos lembretes de agendamento que já venceram';

    public function handle(ReminderService $reminders): int
    {
        $this->info($reminders->dispatchDue().' lembrete(s) enfileirado(s).');

        return self::SUCCESS;
    }
}
