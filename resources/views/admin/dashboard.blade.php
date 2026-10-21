@extends('layouts.app')
@section('title', 'Painel · Barbearia')
@section('content')
    <h1>Painel</h1>
    <p class="muted">Hoje, {{ \Carbon\Carbon::parse($today)->format('d/m/Y') }}</p>
    <div class="stats">
        <div class="card stat"><span class="muted">Confirmados</span><b>{{ $confirmed }}</b></div>
        <div class="card stat"><span class="muted">Concluídos</span><b>{{ $completed }}</b></div>
        <div class="card stat"><span class="muted">Cancelados</span><b>{{ $cancelled }}</b></div>
    </div>
    <p>
        <a class="btn" href="{{ route('admin.agenda') }}">Ver agenda de hoje</a>
        <a class="btn secondary" href="{{ route('admin.report', ['date' => $today]) }}">Faturamento de hoje</a>
        <a class="btn secondary" href="{{ route('admin.services.index') }}">Serviços</a>
    </p>
@endsection
