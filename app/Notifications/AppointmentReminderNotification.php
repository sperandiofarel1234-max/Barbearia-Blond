<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReminderNotification extends Notification
{
    public function __construct(private Appointment $appointment, private string $type) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appointment->loadMissing('service');
        $when = $this->type === '2h' ? 'em 2 horas' : 'amanhã';

        return (new MailMessage)
            ->subject("Lembrete: seu horário é {$when}")
            ->greeting("Olá, {$a->customer_name}!")
            ->line("Lembrete do seu atendimento ({$a->service->name}):")
            ->line('Data: '.$a->startsAt()->format('d/m/Y').' às '.$a->startLabel())
            ->line('Precisa remarcar ou cancelar? Use o link seguro abaixo.')
            ->action('Gerenciar agendamento', route('appointments.manage', $a->manage_token))
            ->salutation('Atenciosamente, Barbearia');
    }
}
