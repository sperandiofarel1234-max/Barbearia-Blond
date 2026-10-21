@extends('layouts.app')
@section('title', ($service->exists ? 'Editar' : 'Novo').' serviço · Barbearia')
@section('content')
    <h1>{{ $service->exists ? 'Editar serviço' : 'Novo serviço' }}</h1>
    <form class="card" method="POST" action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}" style="max-width:520px">
        @csrf
        @if ($service->exists) @method('PUT') @endif

        <label for="name">Nome</label>
        <input type="text" id="name" name="name" value="{{ old('name', $service->name) }}" maxlength="100" required>
        @error('name')<p class="field-error">{{ $message }}</p>@enderror

        <label for="description">Descrição (opcional)</label>
        <textarea id="description" name="description" rows="3" maxlength="500">{{ old('description', $service->description) }}</textarea>
        @error('description')<p class="field-error">{{ $message }}</p>@enderror

        <label for="duration_minutes">Duração (minutos)</label>
        <input type="number" id="duration_minutes" name="duration_minutes" min="1" max="600" step="1" value="{{ old('duration_minutes', $service->duration_minutes) }}" required>
        @error('duration_minutes')<p class="field-error">{{ $message }}</p>@enderror

        <label for="price">Preço (R$)</label>
        <input type="text" inputmode="decimal" id="price" name="price" value="{{ old('price', $service->exists ? number_format((float) $service->price, 2, ',', '') : '') }}" placeholder="50,00" required>
        @error('price')<p class="field-error">{{ $message }}</p>@enderror
        <p class="muted">Alterar o preço não muda agendamentos já feitos.</p>

        <label><input type="checkbox" name="active" value="1" @checked(old('active', $service->active))> Ativo (aceita novos agendamentos)</label>

        <p style="margin-top:16px"><button class="btn" type="submit">Salvar</button> <a class="btn secondary" href="{{ route('admin.services.index') }}">Voltar</a></p>
    </form>
@endsection
