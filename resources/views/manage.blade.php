@extends('layouts.app')
@section('title', 'Meu agendamento')
@section('content')
    <h1>Seu agendamento</h1>

    <div class="card">
        <p><span class="badge {{ $appointment->status->value }}">{{ $appointment->status->label() }}</span></p>
        <table>
            <tr><th scope="row">Serviço</th><td>{{ $appointment->service->name }}</td></tr>
            <tr><th scope="row">Data</th><td>{{ $appointment->startsAt()->format('d/m/Y') }}</td></tr>
            <tr><th scope="row">Horário</th><td>{{ $appointment->startLabel() }} às {{ $appointment->endLabel() }}</td></tr>
            <tr><th scope="row">Duração</th><td>{{ $appointment->duration_minutes }} min</td></tr>
            <tr><th scope="row">Preço</th><td>{{ \App\Support\Money::brl($appointment->price) }}</td></tr>
            <tr><th scope="row">Cliente</th><td>{{ $appointment->customer_name }}</td></tr>
        </table>
    </div>

    @if ($appointment->isConfirmed())
        @if ($canChange)
            <div class="card">
                <p>Você pode remarcar ou cancelar até <strong>{{ $deadline->format('d/m/Y \à\s H:i') }}</strong> ({{ $minHours }} hora(s) antes do horário).</p>
                <a class="btn secondary" href="{{ route('appointments.reschedule', $token) }}">Remarcar</a>
                <form class="inline" method="POST" action="{{ route('appointments.cancel', $token) }}" onsubmit="return confirm('Deseja realmente cancelar este agendamento?')">
                    @csrf
                    <button class="btn danger" type="submit">Cancelar agendamento</button>
                </form>
            </div>
        @else
            <div class="card muted">O prazo para remarcar ou cancelar online (mínimo de {{ $minHours }} hora(s) de antecedência) já passou. Entre em contato com a barbearia.</div>
        @endif
    @elseif ($appointment->status === \App\Enums\AppointmentStatus::Cancelled)
        <div class="card muted">Este agendamento foi cancelado. <a href="{{ route('home') }}">Fazer um novo agendamento</a></div>
    @endif

    <p class="muted">Guarde o endereço desta página: ele é a sua chave de acesso e não deve ser compartilhado.</p>
@endsection
