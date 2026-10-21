<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Http\Requests\RescheduleRequest;
use App\Models\Appointment;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BusinessHours;
use Illuminate\Http\Request;

/**
 * Gerenciamento público: o cliente só enxerga/altera o registro do token informado.
 * Token inexistente ou malformado → 404 idêntico (sem enumeração).
 */
class ManageAppointmentController extends Controller
{
    public function show(string $token, BusinessHours $hours)
    {
        $appointment = $this->find($token);

        return view('manage', [
            'appointment' => $appointment->load('service'),
            'token' => $token,
            'canChange' => $hours->canChange($appointment),
            'deadline' => $hours->changeDeadline($appointment),
            'minHours' => $hours->minHoursToChange(),
        ]);
    }

    public function cancel(string $token, BookingService $booking)
    {
        $appointment = $this->find($token);

        try {
            $booking->cancel($appointment);
        } catch (BookingException $e) {
            return redirect()->route('appointments.manage', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('appointments.manage', $token)->with('success', 'Agendamento cancelado. O horário foi liberado.');
    }

    public function rescheduleForm(Request $request, string $token, BusinessHours $hours, AvailabilityService $availability)
    {
        $appointment = $this->find($token)->load('service');

        if (! $hours->canChange($appointment)) {
            try {
                $hours->assertCanChange($appointment, 'remarcar');
            } catch (BookingException $e) {
                return redirect()->route('appointments.manage', $token)->with('error', $e->getMessage());
            }
        }

        $date = $hours->parseDate($request->query('date')) ?? $hours->now()->startOfDay();
        while (! $request->query('date') && ! $hours->isOpenDay($date)) {
            $date = $date->addDay();
        }

        $slots = $date->greaterThanOrEqualTo($hours->now()->startOfDay())
            ? $availability->slots($appointment->duration_minutes, $date, $appointment->id)
            : [];

        return view('reschedule', [
            'appointment' => $appointment,
            'token' => $token,
            'date' => $date->format('Y-m-d'),
            'slots' => $slots,
            'closed' => ! $hours->isOpenDay($date),
            'today' => $hours->now()->format('Y-m-d'),
        ]);
    }

    public function reschedule(RescheduleRequest $request, string $token, BookingService $booking)
    {
        $appointment = $this->find($token);

        try {
            $booking->reschedule($appointment, $request->validated('date'), $request->validated('time'));
        } catch (BookingException $e) {
            return redirect()
                ->route('appointments.reschedule', ['token' => $token, 'date' => $request->input('date')])
                ->with('error', $e->getMessage());
        }

        return redirect()->route('appointments.manage', $token)->with('success', 'Agendamento remarcado com sucesso.');
    }

    private function find(string $token): Appointment
    {
        return Appointment::findByToken($token) ?? abort(404);
    }
}
