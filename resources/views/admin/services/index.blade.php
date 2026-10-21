@extends('layouts.app')
@section('title', 'Serviços · Barbearia')
@section('content')
    <h1>Serviços</h1>
    <p><a class="btn" href="{{ route('admin.services.create') }}">Novo serviço</a></p>
    <div class="card table-scroll">
        <table>
            <thead><tr><th>Nome</th><th>Duração</th><th>Preço</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($services as $service)
                <tr>
                    <td>{{ $service->name }}<br><span class="muted">{{ $service->description }}</span></td>
                    <td>{{ $service->duration_minutes }} min</td>
                    <td>{{ \App\Support\Money::brl($service->price) }}</td>
                    <td>{{ $service->active ? 'Ativo' : 'Inativo' }}</td>
                    <td class="right">
                        <a class="btn secondary small" href="{{ route('admin.services.edit', $service) }}">Editar</a>
                        <form class="inline" method="POST" action="{{ route('admin.services.toggle', $service) }}">
                            @csrf @method('PATCH')
                            <button class="btn secondary small" type="submit">{{ $service->active ? 'Desativar' : 'Ativar' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nenhum serviço cadastrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
