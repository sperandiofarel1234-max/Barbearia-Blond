<?php

use Illuminate\Support\Facades\Schedule;

// Verifica a cada minuto os lembretes vencidos e os enfileira (worker: php artisan queue:work).
Schedule::command('reminders:dispatch')->everyMinute()->withoutOverlapping();
