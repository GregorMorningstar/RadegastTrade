<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the code that confirms accepting an invitation to units (it goes to the invited person) or consenting
 * to it (it goes to the person who consents).
 */
class ColleagueCodeNotification extends Notification
{
    public const VALID_MINUTES = 15;

    /**
     * @param  'accept'|'consent'  $purpose
     */
    public function __construct(#[SensitiveParameter] public string $code, public string $purpose, public string $person, public string $units)
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
        $line = $this->purpose === 'consent'
            ? 'Wyrażasz zgodę, aby „'.MailText::plain($this->person, 120).'” przyjął(-ęła) zaproszenie do jednostek: '.MailText::plain($this->units, 300).'. Aby potwierdzić, wpisz w panelu ten kod:'
            : 'Przyjmujesz zaproszenie do jednostek: '.MailText::plain($this->units, 300).'. Aby potwierdzić, wpisz w panelu ten kod:';

        return (new MailMessage)
            ->subject('Kod potwierdzający – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line($line)
            ->line('**'.$this->code.'**')
            ->line('Kod jest ważny '.self::VALID_MINUTES.' minut.')
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość: bez kodu nic się nie zmieni.');
    }
}
