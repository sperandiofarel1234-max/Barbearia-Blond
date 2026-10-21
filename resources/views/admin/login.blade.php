@extends('layouts.app')
@section('title', 'Entrar · Barbearia')
@section('content')
    <h1>Área do barbeiro</h1>
    <form class="card" method="POST" action="{{ route('login.store') }}" style="max-width:420px">
        @csrf
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        @error('email')<p class="field-error">{{ $message }}</p>@enderror
        <label for="password">Senha</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        @error('password')<p class="field-error">{{ $message }}</p>@enderror
        <p style="margin-top:16px"><button class="btn" type="submit">Entrar</button></p>
    </form>
@endsection
