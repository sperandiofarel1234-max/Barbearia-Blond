@extends('layouts.app')
@section('title', 'Muitas tentativas · Barbearia')
@section('content')
    <h1>Muitas tentativas</h1>
    <p class="muted">Aguarde um instante antes de tentar novamente.</p>
    <p><a class="btn secondary" href="{{ route('home') }}">Voltar ao início</a></p>
@endsection
