<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Dados de exemplo para demonstração (opcional):
 *   php artisan db:seed --class=DemoSeeder
 *
 * Cria atendimentos de hoje (concluídos, confirmado e cancelado) e dois de amanhã,
 * todos com clientes fictícios (domínio example.com). Pode ser executado várias vezes.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ServiceSeeder::class);

        $tz = config('barbearia.timezone');
        $today = CarbonImmutable::now($tz);
        $tomorrow = $today->addDay();
        $corte = Service::where('name', 'Corte')->firstOrFail();
        $barba = Service::where('name', 'Barba')->firstOrFail();
        $combo = Service::where('name', 'Corte e barba')->firstOrFail();

        Appointment::where('customer_email', 'like', '%@demo.example.com')->delete();

        $rows = [
            [$corte, $today, '09:00', AppointmentStatus::Completed, 'João Demo'],
            [$combo, $today, '10:00', AppointmentStatus::Completed, 'Pedro Demo'],
            [$barba, $today, '11:30', AppointmentStatus::Cancelled, 'Lucas Demo'],
            [$corte, $today, '15:00', AppointmentStatus::Confirmed, 'Rafael Demo'],
            [$combo, $tomorrow, '10:00', AppointmentStatus::Confirmed, 'Bruno Demo'],
            [$barba, $tomorrow, '14:20', AppointmentStatus::Confirmed, 'Diego Demo'],
        ];

        foreach ($rows as $i => [$service, $day, $start, $status, $name]) {
            if (! $this->isOpenDay($day)) {
                continue; // domingo: sem atendimento
            }
            $startAt = CarbonImmutable::parse($day->format('Y-m-d').' '.$start, $tz);
            $token = Appointment::generateToken();

            $appointment = (new Appointment)->forceFill([
                'service_id' => $service->id,
                'customer_name' => $name,
                'customer_phone' => '1190000'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'customer_email' => strtolower(explode(' ', $name)[0]).'@demo.example.com',
                'date' => $startAt->format('Y-m-d'),
                'start_time' => $startAt->format('H:i:s'),
                'end_time' => $startAt->addMinutes($service->duration_minutes)->format('H:i:s'),
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'status' => $status,
                'manage_token_hash' => Appointment::hashToken($token),
                'manage_token' => $token,
                'completed_at' => $status === AppointmentStatus::Completed ? $startAt->addMinutes($service->duration_minutes) : null,
                'cancelled_at' => $status === AppointmentStatus::Cancelled ? $startAt->subDay() : null,
            ]);
            $appointment->save();

            app(ReminderService::class)->sync($appointment);
        }
    }

    private function isOpenDay(CarbonImmutable $day): bool
    {
        return in_array($day->dayOfWeekIso, config('barbearia.open_weekdays'), true);
    }
}
