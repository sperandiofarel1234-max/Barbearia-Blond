<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: serviços nunca são apagados, apenas desativados (preserva histórico).
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20);
            $table->string('customer_email', 190);
            // Data e horários no fuso America/Sao_Paulo (horário de parede da barbearia).
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            // Snapshot do serviço no momento da contratação.
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('price', 10, 2);
            $table->string('status', 20)->default('confirmed');
            // Somente o hash SHA-256 do token de gerenciamento é armazenado.
            $table->char('manage_token_hash', 64)->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['date', 'status', 'start_time']);
        });

        Schema::create('appointment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // created, rescheduled, cancelled, completed
            $table->date('old_date')->nullable();
            $table->time('old_start_time')->nullable();
            $table->date('new_date')->nullable();
            $table->time('new_start_time')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('appointment_id');
        });

        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // 24h, 2h
            $table->dateTime('remind_at');
            // pending, processing, sent, failed, cancelled
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            // Um lembrete de cada tipo por agendamento: evita duplicidade por construção.
            $table->unique(['appointment_id', 'type']);
            $table->index(['status', 'remind_at']);
        });

        // Linha de controle por data, usada como mutex de escrita (ver BookingService).
        Schema::create('day_locks', function (Blueprint $table) {
            $table->date('date')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_locks');
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_histories');
        Schema::dropIfExists('appointments');
    }
};
