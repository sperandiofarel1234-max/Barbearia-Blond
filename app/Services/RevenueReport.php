<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;

/**
 * Relatório de faturamento diário.
 *
 * Definições:
 *  - Data de referência: a data do agendamento (não a do cancelamento). Cada registro
 *    pertence a uma única data, então nunca é contado duas vezes (remarcar muda a data).
 *  - Faturamento realizado: soma do preço histórico dos CONCLUÍDOS da data.
 *  - Receita prevista: soma do preço histórico dos CONFIRMADOS (ainda não concluídos).
 *  - Cancelamentos: agendamentos CANCELADOS cuja data é a selecionada; não entram em valores.
 *  - Ticket médio: realizado / concluídos (somente se concluídos > 0), arredondado no banco.
 *  Nenhum valor indica pagamento efetivo: o sistema não integra meios de pagamento.
 */
class RevenueReport
{
    /** @return array<string, mixed> */
    public function forDate(string $date): array
    {
        $rows = Appointment::onDate($date)
            ->selectRaw('status, COUNT(*) AS total, COALESCE(SUM(price), 0) AS amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($r) => $r->status->value);

        $count = fn (AppointmentStatus $s) => (int) ($rows[$s->value]->total ?? 0);
        $amount = fn (AppointmentStatus $s) => (string) ($rows[$s->value]->amount ?? '0.00');

        $completed = $count(AppointmentStatus::Completed);
        $realized = $amount(AppointmentStatus::Completed);

        $average = null;
        if ($completed > 0) {
            // Divisão decimal feita pelo MySQL (sem float).
            $average = Appointment::onDate($date)
                ->where('status', AppointmentStatus::Completed->value)
                ->selectRaw('ROUND(SUM(price) / COUNT(*), 2) AS avg_price')
                ->value('avg_price');
        }

        return [
            'date' => $date,
            'total' => (int) $rows->sum('total'),
            'cancelled' => $count(AppointmentStatus::Cancelled),
            'completed' => $completed,
            'confirmed' => $count(AppointmentStatus::Confirmed),
            'realized' => $realized,
            'forecast' => $amount(AppointmentStatus::Confirmed),
            'average' => $average,
            'appointments' => Appointment::with('service')->onDate($date)->orderBy('start_time')->orderBy('id')->get(),
        ];
    }
}
