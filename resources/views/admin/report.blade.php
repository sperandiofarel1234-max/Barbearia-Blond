@extends('layouts.app')
@section('title', 'Faturamento · Barbearia')
@section('content')
    <h1>Faturamento diário</h1>
    <form class="card" method="GET" action="{{ route('admin.report') }}">
        <label for="date">Data</label>
        <input type="date" id="date" name="date" value="{{ $report['date'] }}" required style="max-width:220px">
        <p><button class="btn secondary" type="submit">Consultar</button></p>
    </form>

    <h2>{{ \Carbon\Carbon::parse($report['date'])->format('d/m/Y') }}</h2>

    @if ($report['total'] === 0)
        <div class="card muted">Nenhum agendamento nesta data.</div>
    @else
        <div class="stats">
            <div class="card stat"><span class="muted">Agendamentos</span><b>{{ $report['total'] }}</b></div>
            <div class="card stat"><span class="muted">Concluídos</span><b>{{ $report['completed'] }}</b></div>
            <div class="card stat"><span class="muted">Cancelamentos</span><b>{{ $report['cancelled'] }}</b></div>
            <div class="card stat"><span class="muted">Faturamento realizado</span><b>{{ \App\Support\Money::brl($report['realized']) }}</b></div>
            <div class="card stat"><span class="muted">Receita prevista (confirmados)</span><b>{{ \App\Support\Money::brl($report['forecast']) }}</b></div>
            <div class="card stat"><span class="muted">Ticket médio</span><b>{{ $report['average'] !== null ? \App\Support\Money::brl($report['average']) : '—' }}</b></div>
        </div>
        <p class="muted">Realizado = atendimentos marcados como concluídos. Previsto = ainda confirmados, não significa pagamento recebido. Cancelamentos são contados pela data original do agendamento e não entram nos valores. Os preços são os registrados no momento do agendamento.</p>

        <div class="card table-scroll">
            <table>
                <thead><tr><th>Horário</th><th>Cliente</th><th>Serviço</th><th>Preço</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($report['appointments'] as $a)
                    <tr>
                        <td>{{ $a->startLabel() }}–{{ $a->endLabel() }}</td>
                        <td>{{ $a->customer_name }}</td>
                        <td>{{ $a->service->name }}</td>
                        <td>{{ \App\Support\Money::brl($a->price) }}</td>
                        <td><span class="badge {{ $a->status->value }}">{{ $a->status->label() }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
