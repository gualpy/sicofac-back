<?php

namespace App\Notifications;

use App\Models\CompanyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly CompanyInvitation $invitation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/invitaciones/'.$this->invitation->token;

        return (new MailMessage)
            ->subject('Te invitaron a '.$this->invitation->company->name.' - SICOFAC')
            ->greeting('Hola,')
            ->line($this->invitation->invitedBy?->name.' te invito a unirte a '.$this->invitation->company->name.' en SICOFAC.')
            ->action('Aceptar invitacion', $url)
            ->line('Este enlace vence en 7 dias.')
            ->line('Si no esperabas esta invitacion, puedes ignorar este correo.');
    }
}
