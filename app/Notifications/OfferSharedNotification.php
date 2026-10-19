<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Information that an offer was shared with a unit. It only informs: what the person may see comes from the data in the
 * system, not from the e-mail.
 */
class OfferSharedNotification extends Notification
{
    public function __construct(public string $offerNumber, public string $offerTitle, public string $supplier, public string $unitName)
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
            ->subject('Nowa oferta od '.MailText::plain($this->supplier, 80).' – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('„'.MailText::plain($this->supplier, 120).'” udostępnił(a) ofertę '.MailText::plain($this->offerNumber, 40).' („'.MailText::plain($this->offerTitle, 150).'”) dla jednostki „'.MailText::plain($this->unitName, 120).'”.')
            ->action('Zobacz oferty', route('trade.available.index'))
            ->line('Aby zobaczyć ofertę, zaloguj się na swoje konto.');
    }
}
