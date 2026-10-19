<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the code that confirms changing the profile photo.
 */
class AvatarChangeCodeNotification extends Notification
{
    public const VALID_MINUTES = 15;

    public function __construct(#[SensitiveParameter] public string $code)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kod potwierdzający zmianę zdjęcia profilowego – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Ktoś chce zmienić zdjęcie profilowe Twojego konta. Aby potwierdzić, wpisz w ustawieniach konta ten kod:')
            ->line('**'.$this->code.'**')
            ->line('Kod jest ważny '.self::VALID_MINUTES.' minut.')
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość i zmień hasło: bez kodu zdjęcie zostanie bez zmian.');
    }
}
