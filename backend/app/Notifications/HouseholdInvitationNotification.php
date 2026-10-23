<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvitationNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Undangan keluarga bahitung')->line('Anda diundang bergabung dengan keluarga di bahitung.')
            ->action('Lihat undangan', rtrim(config('app.frontend_url'), '/').'/undangan?'.http_build_query(['token' => $this->token]))
            ->line('Undangan berlaku 7 hari. Masuk dengan email penerima yang sudah terverifikasi untuk menerima undangan.');
    }
}
