<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminMagicLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu enlace de acceso - SICOFAC Admin')
            ->greeting('Hola '.$notifiable->name.',')
            ->line('Usa este enlace para entrar directo al panel de Super Administrador.')
            ->action('Entrar al panel', $this->url)
            ->line('Este enlace vence en 15 minutos y solo sirve una vez.')
            ->line('Si no pediste esto, puedes ignorar este correo.');
    }
}
