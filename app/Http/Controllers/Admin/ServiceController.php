<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Models\Service;

class ServiceController extends Controller
{
    public function index()
    {
        return view('admin.services.index', ['services' => Service::orderByDesc('active')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.services.form', ['service' => new Service(['active' => true])]);
    }

    public function store(ServiceRequest $request)
    {
        Service::create($request->validated());

        return redirect()->route('admin.services.index')->with('success', 'Serviço cadastrado.');
    }

    public function edit(Service $service)
    {
        return view('admin.services.form', ['service' => $service]);
    }

    /** Agendamentos antigos mantêm o preço/duração gravados no momento da contratação. */
    public function update(ServiceRequest $request, Service $service)
    {
        $service->update($request->validated());

        return redirect()->route('admin.services.index')->with('success', 'Serviço atualizado.');
    }

    public function toggle(Service $service)
    {
        $service->update(['active' => ! $service->active]);

        return redirect()->route('admin.services.index')
            ->with('success', $service->active ? 'Serviço ativado.' : 'Serviço desativado. O histórico foi preservado.');
    }
}
