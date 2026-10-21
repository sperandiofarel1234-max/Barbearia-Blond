@extends('layouts.app')
@section('title', 'Acesso negado · Barbearia')
@section('content')
    <h1>Acesso negado</h1>
    <p class="muted">Você não tem permissão para acessar esta página.</p>
    <p><a class="btn secondary" href="{{ route('home') }}">Voltar ao início</a></p>
@endsection
