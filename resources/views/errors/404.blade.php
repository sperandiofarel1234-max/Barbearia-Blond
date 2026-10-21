@extends('layouts.app')
@section('title', 'Página não encontrada · Barbearia')
@section('content')
    <h1>Página não encontrada</h1>
    <p class="muted">O endereço acessado não existe ou o link está incorreto.</p>
    <p><a class="btn secondary" href="{{ route('home') }}">Voltar ao início</a></p>
@endsection
