<?php

use App\Http\Controllers\Admin\AgendaController;
use App\Http\Controllers\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ManageAppointmentController;
use Illuminate\Support\Facades\Route;

// --- Área pública do cliente -------------------------------------------------
Route::get('/', [BookingController::class, 'index'])->name('home');
Route::get('/agendar/{service}', [BookingController::class, 'create'])->name('booking.create');
Route::post('/agendar/{service}', [BookingController::class, 'store'])->middleware('throttle:booking')->name('booking.store');

// Gerenciamento por token longo e aleatório (nunca por ID sequencial).
Route::prefix('agendamento/{token}')->middleware('throttle:manage')->group(function () {
    Route::get('/', [ManageAppointmentController::class, 'show'])->name('appointments.manage');
    Route::post('/cancelar', [ManageAppointmentController::class, 'cancel'])->name('appointments.cancel');
    Route::get('/remarcar', [ManageAppointmentController::class, 'rescheduleForm'])->name('appointments.reschedule');
    Route::post('/remarcar', [ManageAppointmentController::class, 'reschedule'])->name('appointments.reschedule.store');
});

// --- Autenticação administrativa ---------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// --- Área administrativa -------------------------------------------------------
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/servicos', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/servicos/novo', [ServiceController::class, 'create'])->name('services.create');
    Route::post('/servicos', [ServiceController::class, 'store'])->name('services.store');
    Route::get('/servicos/{service}/editar', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/servicos/{service}', [ServiceController::class, 'update'])->name('services.update');
    // Serviços nunca são apagados: apenas ativados/desativados (preserva o histórico).
    Route::patch('/servicos/{service}/alternar', [ServiceController::class, 'toggle'])->name('services.toggle');

    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
    Route::post('/agendamentos/{appointment}/concluir', [AdminAppointmentController::class, 'complete'])->name('appointments.complete');

    Route::get('/faturamento', [ReportController::class, 'daily'])->name('report');
});
