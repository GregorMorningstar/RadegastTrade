<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the link to an invitation to trade cooperation. The link only opens the invitation: answering needs
 * signing in as a person who represents the invited organization.
 */
class TradeInvitationNotification extends Notification
{
    public function __construct(
        #[SensitiveParameter] public string $token,
        public string $inviterOrganization,
        public string $offersOrBuys,
        public string $message = '',
        public ?string $expiresAt = null,
    ) {
        //
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Zaproszenie do współpracy handlowej – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Organizacja „'.MailText::plain($this->inviterOrganization, 120).'” zaprasza do współpracy handlowej: '.$this->offersOrBuys.'.');

        if ($this->message !== '') {
            $mail->line('Wiadomość od zapraszającego (treść napisana przez niego, nie przez platformę): '.MailText::plain($this->message, 500));
        }

        return $mail
            ->action('Zobacz zaproszenie', route('trade.invitations.show', $this->token))
            ->line('Odpowiedzieć może tylko osoba, która reprezentuje Twoją organizację i jest zalogowana. Samo kliknięcie linku niczego nie akceptuje.')
            ->line($this->expiresAt ? 'Zaproszenie jest ważne do '.$this->expiresAt.'.' : '');
    }
}
