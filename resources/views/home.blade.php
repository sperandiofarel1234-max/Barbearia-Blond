@extends('layouts.app')
@section('title', 'Agendar horário · Barbearia')
@section('content')
    <h1>Agende seu horário</h1>
    <p class="muted">Atendemos de segunda a sábado, das 9h às 18h. Escolha um serviço para ver os horários disponíveis.</p>

    @forelse ($services as $service)
        @if ($loop->first)<div class="grid">@endif
        <div class="card">
            <h2 style="margin-top:0">{{ $service->name }}</h2>
            @if ($service->description)<p class="muted">{{ $service->description }}</p>@endif
            <p><strong>{{ \App\Support\Money::brl($service->price) }}</strong> · {{ $service->duration_minutes }} min</p>
            <a class="btn" href="{{ route('booking.create', $service) }}">Escolher horário</a>
        </div>
        @if ($loop->last)</div>@endif
    @empty
        <div class="card">Nenhum serviço disponível no momento. Volte mais tarde.</div>
    @endforelse
@endsection
