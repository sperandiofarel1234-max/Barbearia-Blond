@extends('layouts.app')
@section('title', 'Agendar '.$service->name)
@section('content')
    <h1>{{ $service->name }}</h1>
    <p class="muted">{{ $service->duration_minutes }} min · {{ \App\Support\Money::brl($service->price) }}</p>

    <form class="card" method="GET" action="{{ route('booking.create', $service) }}">
        <label for="date">1. Escolha a data</label>
        <input type="date" id="date" name="date" value="{{ $date }}" min="{{ $today }}" required>
        <p><button class="btn secondary" type="submit">Ver horários</button></p>
    </form>

    <form class="card" method="POST" action="{{ route('booking.store', $service) }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <h2 style="margin-top:0">2. Horários em {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</h2>

        @if ($closed)
            <p class="muted">A barbearia não atende aos domingos. Escolha outra data.</p>
        @elseif (empty($slots))
            <p class="muted">Não há horários disponíveis nesta data. Tente outro dia.</p>
        @else
            <fieldset style="border:0;padding:0;margin:0">
                <legend class="muted">Selecione um horário</legend>
                <div class="slots">
                    @foreach ($slots as $slot)
                        <label><input type="radio" name="time" value="{{ $slot }}" @checked(old('time') === $slot) required><span>{{ $slot }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            @error('time')<p class="field-error">{{ $message }}</p>@enderror

            <h2>3. Seus dados</h2>
            <label for="customer_name">Nome</label>
            <input type="text" id="customer_name" name="customer_name" value="{{ old('customer_name') }}" maxlength="120" required autocomplete="name">
            @error('customer_name')<p class="field-error">{{ $message }}</p>@enderror

            <label for="customer_phone">Telefone (com DDD)</label>
            <input type="tel" id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" placeholder="(11) 98765-4321" required autocomplete="tel">
            @error('customer_phone')<p class="field-error">{{ $message }}</p>@enderror

            <label for="customer_email">E-mail (para confirmação e lembretes)</label>
            <input type="email" id="customer_email" name="customer_email" value="{{ old('customer_email') }}" maxlength="190" required autocomplete="email">
            @error('customer_email')<p class="field-error">{{ $message }}</p>@enderror

            <p style="margin-top:16px"><button class="btn" type="submit">Confirmar agendamento</button></p>
        @endif
        @error('date')<p class="field-error">{{ $message }}</p>@enderror
    </form>
@endsection
