<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the code that confirms removing the API key of a connector.
 */
class ApiKeyRemovalCodeNotification extends Notification
{
    public const VALID_MINUTES = 15;

    public function __construct(#[SensitiveParameter] public string $code, public string $connectorName = '')
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
            ->subject('Kod potwierdzający usunięcie klucza API – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Ktoś poprosił o usunięcie klucza API konektora „'.$this->connectorName.'”. Aby potwierdzić, wpisz w ustawieniach konta ten kod:')
            ->line('**'.$this->code.'**')
            ->line('Kod jest ważny '.self::VALID_MINUTES.' minut. Po usunięciu konektor używający tego klucza przestanie działać.')
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość: bez kodu klucz zostanie na swoim miejscu.');
    }
}
