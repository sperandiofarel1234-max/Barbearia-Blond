<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\BusinessHours;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    public function index(Request $request, BusinessHours $hours)
    {
        $date = $hours->parseDate($request->query('date')) ?? $hours->now()->startOfDay();

        // Ordenação feita no banco, não no navegador.
        $appointments = Appointment::with('service')
            ->onDate($date->format('Y-m-d'))
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return view('admin.agenda', [
            'date' => $date->format('Y-m-d'),
            'appointments' => $appointments,
            'now' => $hours->now(),
        ]);
    }
}
