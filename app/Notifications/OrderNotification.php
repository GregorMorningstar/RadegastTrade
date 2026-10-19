<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Information about an order: placed (to the seller) or its status changed (to the other side). */
class OrderNotification extends Notification
{
    public function __construct(public int $orderId, public string $number, public string $headline, public string $detail = '')
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
        $mail = (new MailMessage)
            ->subject($this->headline.' – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line($this->headline.' (zamówienie '.$this->number.').');

        if ($this->detail !== '') {
            $mail->line(MailText::plain($this->detail, 500));
        }

        return $mail->action('Zobacz zamówienie', route('trade.orders.show', $this->orderId));
    }
}
