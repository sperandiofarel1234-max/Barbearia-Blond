@extends('layouts.app')
@section('title', 'Remarcar agendamento')
@section('content')
    <h1>Remarcar agendamento</h1>
    <p class="muted">{{ $appointment->service->name }} · atual: {{ $appointment->startsAt()->format('d/m/Y') }} às {{ $appointment->startLabel() }} · {{ $appointment->duration_minutes }} min</p>

    <form class="card" method="GET" action="{{ route('appointments.reschedule', $token) }}">
        <label for="date">Nova data</label>
        <input type="date" id="date" name="date" value="{{ $date }}" min="{{ $today }}" required>
        <p><button class="btn secondary" type="submit">Ver horários</button></p>
    </form>

    <form class="card" method="POST" action="{{ route('appointments.reschedule.store', $token) }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <h2 style="margin-top:0">Horários em {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</h2>
        @if ($closed)
            <p class="muted">A barbearia não atende aos domingos. Escolha outra data.</p>
        @elseif (empty($slots))
            <p class="muted">Não há horários disponíveis nesta data. Tente outro dia.</p>
        @else
            <fieldset style="border:0;padding:0;margin:0">
                <legend class="muted">Selecione o novo horário</legend>
                <div class="slots">
                    @foreach ($slots as $slot)
                        <label><input type="radio" name="time" value="{{ $slot }}" required><span>{{ $slot }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            @error('time')<p class="field-error">{{ $message }}</p>@enderror
            <p><button class="btn" type="submit">Confirmar remarcação</button></p>
        @endif
        @error('date')<p class="field-error">{{ $message }}</p>@enderror
    </form>
    <p><a href="{{ route('appointments.manage', $token) }}">← Voltar ao agendamento</a></p>
@endsection
