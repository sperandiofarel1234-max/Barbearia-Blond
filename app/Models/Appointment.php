<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * Somente dados informados pelo cliente são atribuíveis em massa.
     * Status, preço, duração, horários e token são definidos exclusivamente no servidor.
     */
    protected $fillable = ['customer_name', 'customer_phone', 'customer_email'];

    protected $hidden = ['manage_token_hash', 'manage_token'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'status' => AppointmentStatus::class,
            'manage_token' => 'encrypted',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentHistory::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    /** Agendamentos que ocupam a agenda (tudo exceto cancelados). */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->where('status', '!=', AppointmentStatus::Cancelled->value);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->where('date', $date);
    }

    // --- Token público de gerenciamento -------------------------------------

    /** 256 bits de entropia, gerados por CSPRNG. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        // Formato inválido nem chega ao banco.
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return static::where('manage_token_hash', static::hashToken($token))->first();
    }

    // --- Horários -----------------------------------------------------------

    public function dateString(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dateString().' '.$this->start_time, config('barbearia.timezone'));
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dateString().' '.$this->end_time, config('barbearia.timezone'));
    }

    public function startLabel(): string
    {
        return substr($this->start_time, 0, 5);
    }

    public function endLabel(): string
    {
        return substr($this->end_time, 0, 5);
    }

    // --- Ciclo de vida ------------------------------------------------------

    /**
     * Aplica uma transição de status validada. Único ponto de mudança de status.
     */
    public function transitionTo(AppointmentStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new BookingException(sprintf(
                'Não é possível alterar um agendamento %s para %s.',
                mb_strtolower($this->status->label()),
                mb_strtolower($next->label()),
            ));
        }

        $this->status = $next;
    }

    public function isConfirmed(): bool
    {
        return $this->status === AppointmentStatus::Confirmed;
    }
}
