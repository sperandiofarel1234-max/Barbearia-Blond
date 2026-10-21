@extends('layouts.app')
@section('title', 'Agenda · Barbearia')
@section('content')
    <h1>Agenda do dia</h1>
    <form class="card" method="GET" action="{{ route('admin.agenda') }}">
        <label for="date">Data</label>
        <input type="date" id="date" name="date" value="{{ $date }}" required style="max-width:220px">
        <p><button class="btn secondary" type="submit">Consultar</button></p>
    </form>

    <h2>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</h2>
    <div class="card table-scroll">
        @if ($appointments->isEmpty())
            <p class="muted">Nenhum agendamento nesta data.</p>
        @else
            <table>
                <thead><tr><th>Horário</th><th>Cliente</th><th>Serviço</th><th>Duração</th><th>Preço</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($appointments as $a)
                    <tr>
                        <td>{{ $a->startLabel() }}–{{ $a->endLabel() }}</td>
                        <td>{{ $a->customer_name }}<br><span class="muted">{{ $a->customer_phone }}</span></td>
                        <td>{{ $a->service->name }}</td>
                        <td>{{ $a->duration_minutes }} min</td>
                        <td>{{ \App\Support\Money::brl($a->price) }}</td>
                        <td><span class="badge {{ $a->status->value }}">{{ $a->status->label() }}</span></td>
                        <td class="right">
                            @if ($a->isConfirmed() && $a->startsAt()->lessThanOrEqualTo($now))
                                <form class="inline" method="POST" action="{{ route('admin.appointments.complete', $a) }}">
                                    @csrf
                                    <button class="btn small" type="submit">Concluir</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
