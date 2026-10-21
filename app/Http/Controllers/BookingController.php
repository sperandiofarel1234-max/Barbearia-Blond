<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Http\Requests\BookingRequest;
use App\Models\Service;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BusinessHours;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index()
    {
        return view('home', ['services' => Service::active()->orderBy('name')->get()]);
    }

    public function create(Request $request, Service $service, BusinessHours $hours, AvailabilityService $availability)
    {
        abort_unless($service->active, 404);

        $date = $hours->parseDate($request->query('date')) ?? $this->defaultDate($hours);
        $slots = $date->startOfDay()->greaterThanOrEqualTo($hours->now()->startOfDay())
            ? $availability->slots($service->duration_minutes, $date)
            : [];

        return view('book', [
            'service' => $service,
            'date' => $date->format('Y-m-d'),
            'slots' => $slots,
            'closed' => ! $hours->isOpenDay($date),
            'today' => $hours->now()->format('Y-m-d'),
        ]);
    }

    public function store(BookingRequest $request, Service $service, BookingService $booking)
    {
        if (! $service->active) {
            return redirect()->route('home')->with('error', 'Este serviço não está disponível para agendamento.');
        }

        try {
            $appointment = $booking->create(
                $service,
                $request->safe()->only(['customer_name', 'customer_phone', 'customer_email']),
                $request->validated('date'),
                $request->validated('time'),
            );
        } catch (BookingException $e) {
            return redirect()
                ->route('booking.create', ['service' => $service, 'date' => $request->input('date')])
                ->withInput($request->except('time'))
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('appointments.manage', $appointment->manage_token)
            ->with('success', 'Agendamento confirmado! Guarde o link desta página: ele é a sua forma de consultar, remarcar ou cancelar.');
    }

    /** Primeiro dia de atendimento a partir de hoje. */
    private function defaultDate(BusinessHours $hours)
    {
        $d = $hours->now()->startOfDay();
        while (! $hours->isOpenDay($d)) {
            $d = $d->addDay();
        }

        return $d;
    }
}
