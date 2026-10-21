@extends('layouts.app')
@section('title', 'Sessão expirada · Barbearia')
@section('content')
    <h1>Sessão expirada</h1>
    <p class="muted">Sua sessão expirou. Volte, recarregue a página e tente novamente.</p>
    <p><a class="btn secondary" href="{{ route('home') }}">Voltar ao início</a></p>
@endsection
