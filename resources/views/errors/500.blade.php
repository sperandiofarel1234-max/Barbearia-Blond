@extends('layouts.app')
@section('title', 'Erro inesperado · Barbearia')
@section('content')
    <h1>Erro inesperado</h1>
    <p class="muted">Algo deu errado do nosso lado. Tente novamente em instantes.</p>
    <p><a class="btn secondary" href="{{ route('home') }}">Voltar ao início</a></p>
@endsection
