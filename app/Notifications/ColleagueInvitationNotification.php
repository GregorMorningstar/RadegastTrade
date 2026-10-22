<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail about an invitation of a person of the organization to units of a cooperation. It carries no code and no
 * link that acts: the person signs in and answers on the cooperation page.
 */
class ColleagueInvitationNotification extends Notification
{
    /**
     * @param  'invited'|'consent'  $kind  "invited": the person is invited; "consent": a leader asks the one who manages them for consent
     */
    public function __construct(public string $kind, public string $who, public string $units)
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
        $mail = (new MailMessage)->greeting('Dzień dobry '.$notifiable->name.',');

        if ($this->kind === 'consent') {
            return $mail
                ->subject('Prośba o zgodę na współpracę handlową – '.OrganizationInvitationNotification::PLATFORM)
                ->line('Osoba „'.MailText::plain($this->who, 120).'” prosi o zgodę na przyjęcie zaproszenia do jednostek: '.MailText::plain($this->units, 300).'.')
                ->line('Zgodę wyrazisz w panelu (Handel → Współpraca); potwierdzisz ją kodem, który dostaniesz osobnym e-mailem.');
        }

        return $mail
            ->subject('Zaproszenie do współpracy handlowej w organizacji – '.OrganizationInvitationNotification::PLATFORM)
            ->line('Osoba „'.MailText::plain($this->who, 120).'” zaprasza Cię do jednostek: '.MailText::plain($this->units, 300).'. Po przyjęciu będziesz mogła(-ął) składać zamówienia dla tych jednostek.')
            ->line('Zaproszenie przyjmiesz w panelu (Handel → Współpraca) po wpisaniu kodu z e-maila. Samo kliknięcie niczego nie przyznaje.');
    }
}
