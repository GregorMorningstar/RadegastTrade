<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the code that confirms authorising a person to place orders for a unit.
 */
class OrderPermissionCodeNotification extends Notification
{
    public const VALID_MINUTES = 15;

    public function __construct(#[SensitiveParameter] public string $code, public string $unitName, public string $personName)
    {
        //
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kod potwierdzający upoważnienie do zamawiania – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Ktoś chce upoważnić osobę „'.MailText::plain($this->personName, 120).'” do składania zamówień w imieniu jednostki „'.MailText::plain($this->unitName, 120).'”. Aby potwierdzić, wpisz w panelu ten kod:')
            ->line('**'.$this->code.'**')
            ->line('Kod jest ważny '.self::VALID_MINUTES.' minut.')
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość: bez kodu nikt nie dostanie uprawnienia.');
    }
}
