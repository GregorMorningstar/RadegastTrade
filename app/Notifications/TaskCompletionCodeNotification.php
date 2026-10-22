<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with the code that confirms that the person has done a task which asks for it. It goes to the person who marks
 * the task as done, so entering it proves that they received it and are the one who did it.
 */
class TaskCompletionCodeNotification extends Notification
{
    public const VALID_MINUTES = 15;

    public function __construct(#[SensitiveParameter] public string $code, public string $title = '')
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
            ->subject('Kod potwierdzający wykonanie zadania – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Oznaczasz jako wykonane zadanie „'.MailText::plain($this->title, 150).'”. Aby potwierdzić, że to Ty je wykonałeś(-aś), wpisz w panelu ten kod:')
            ->line('**'.$this->code.'**')
            ->line('Kod jest ważny '.self::VALID_MINUTES.' minut. Zadanie zostanie zapisane jako wykonane dopiero po jego wpisaniu.')
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość.');
    }
}
