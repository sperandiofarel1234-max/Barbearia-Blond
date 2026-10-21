<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\BusinessHours;

class DashboardController extends Controller
{
    public function __invoke(BusinessHours $hours)
    {
        $today = $hours->now()->format('Y-m-d');
        $counts = Appointment::onDate($today)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.dashboard', [
            'today' => $today,
            'confirmed' => (int) ($counts[AppointmentStatus::Confirmed->value] ?? 0),
            'completed' => (int) ($counts[AppointmentStatus::Completed->value] ?? 0),
            'cancelled' => (int) ($counts[AppointmentStatus::Cancelled->value] ?? 0),
        ]);
    }
}
