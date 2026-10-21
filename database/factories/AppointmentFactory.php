<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $token = Appointment::generateToken();

        return [
            'service_id' => Service::factory(),
            'customer_name' => fake()->name(),
            'customer_phone' => '11987654321',
            'customer_email' => fake()->safeEmail(),
            'date' => '2026-11-03',
            'start_time' => '10:00:00',
            'end_time' => '10:40:00',
            'duration_minutes' => 40,
            'price' => '50.00',
            'status' => AppointmentStatus::Confirmed,
            'manage_token_hash' => Appointment::hashToken($token),
            'manage_token' => $token,
        ];
    }

    /** Define data, início e fim (calculado pela duração). */
    public function at(string $date, string $start, int $minutes = 40): static
    {
        return $this->state(fn () => [
            'date' => $date,
            'start_time' => $start.':00',
            'end_time' => Carbon::parse("$date $start")->addMinutes($minutes)->format('H:i:s'),
            'duration_minutes' => $minutes,
        ]);
    }

    public function priced(string $price): static
    {
        return $this->state(['price' => $price]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
