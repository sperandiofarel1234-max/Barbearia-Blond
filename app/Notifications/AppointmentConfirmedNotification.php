<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentConfirmedNotification extends Notification
{
    public function __construct(private Appointment $appointment, private bool $rescheduled = false)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appointment->loadMissing('service');

        return (new MailMessage)
            ->subject($this->rescheduled ? 'Agendamento remarcado' : 'Agendamento confirmado')
            ->greeting("Olá, {$a->customer_name}!")
            ->line($this->rescheduled ? 'Seu agendamento foi remarcado:' : 'Seu agendamento está confirmado:')
            ->line("Serviço: {$a->service->name}")
            ->line('Data: '.$a->startsAt()->format('d/m/Y').' às '.$a->startLabel())
            ->line('Para consultar, remarcar ou cancelar, use o link seguro abaixo (não o compartilhe):')
            ->action('Gerenciar agendamento', route('appointments.manage', $a->manage_token));
    }
}
