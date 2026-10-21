<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\BookingService;

class AppointmentController extends Controller
{
    public function complete(Appointment $appointment, BookingService $booking)
    {
        try {
            $booking->complete($appointment);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Atendimento marcado como concluído.');
    }
}
