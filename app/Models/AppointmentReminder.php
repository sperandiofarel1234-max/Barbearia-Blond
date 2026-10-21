<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentReminder extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ReminderStatus::class,
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
