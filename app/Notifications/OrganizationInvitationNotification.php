<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    public const PLATFORM = 'RadegastTradeB2BPlatform';

    /**
     * @param  string  $verificationCode  jawny 6-cyfrowy klucz (w bazie jest tylko jego hash)
     */
    public function __construct(
        public OrganizationInvitation $invitation,
        public string $verificationCode,
    ) {
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
        $platform = self::PLATFORM;
        $organizationName = $this->invitation->organization?->name;
        $inviter = $this->invitation->inviter;

        return (new MailMessage)
            ->subject("Zaproszenie do {$platform}")
            ->greeting('Dzień dobry,')
            ->line("Administrator **{$inviter->name}** wysłał Ci zaproszenie do platformy **{$platform}**.")
            ->line($organizationName
                ? "Zostaniesz właścicielem organizacji **{$organizationName}**."
                : 'Zostaniesz właścicielem nowej organizacji. Jej nazwę i dane podasz po przyjęciu zaproszenia.')
            ->line('**Twój klucz zaproszenia:**')
            ->line("## {$this->verificationCode}")
            ->line('Klucz jest jednorazowy i potrzebny do przyjęcia zaproszenia. Nie udostępniaj go nikomu.')
            ->line('**Jak aktywować konto:**')
            ->line('1. Otwórz przycisk poniżej. Jeśli nie masz jeszcze konta, zostaniesz przeniesiony do rejestracji (adres e-mail jest już wypełniony), a jeśli masz, do logowania.')
            ->line('2. Po zalogowaniu wpisz klucz zaproszenia.')
            ->line('3. Uzupełnij dane organizacji.')
            ->action('Przyjmij zaproszenie', route('organization-invitations.show', $this->invitation))
            ->line('Zaproszenie jest ważne do '.$this->invitation->expires_at->format('d.m.Y H:i').'.')
            ->line('Jeśli to nie Ty oczekiwałeś tego zaproszenia, zignoruj tę wiadomość.')
            ->salutation("Zespół {$platform}");
    }
}
