<?php

namespace App\Notifications;

use App\Enums\HeadquarterType;
use App\Enums\UserRole;
use App\Models\OrganizationInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    public const PLATFORM = 'RadegastTradeB2BPlatform';

    /**
     * @param  string  $verificationCode  plain 6-digit key (only its hash is stored in the database)
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
        $headquarter = $this->invitation->headquarter();
        $type = HeadquarterType::forRole($this->invitation->role);

        // a headquarters manager is invited by the organization owner, and an organization owner by the administrator
        $branchType = HeadquarterType::forLeaderRole($this->invitation->role);
        $isBranch = $branchType !== null;
        $workerType = collect(HeadquarterType::cases())->first(fn (HeadquarterType $type) => $type->workerRole() === $this->invitation->role);
        $branch = $this->invitation->shopBranch ?? $this->invitation->warehouseBranch;
        $branchLabel = $branchType === HeadquarterType::Shop ? 'sklepu' : 'hurtowni';
        $inviterLabel = match (true) {
            $workerType !== null => 'Kierownik placówki',
            $isBranch => 'Kierownik siedziby',
            $type !== null => 'Właściciel organizacji',
            default => 'Administrator',
        };

        return (new MailMessage)
            ->subject("Zaproszenie do {$platform}")
            ->greeting('Dzień dobry,')
            ->line("{$inviterLabel} **{$inviter->name}** wysłał Ci zaproszenie do platformy **{$platform}**.")
            ->line(match (true) {
                $workerType !== null => "Zostaniesz pracownikiem placówki **{$branch?->name}** (organizacja **{$organizationName}**).",
                $isBranch => "Zostaniesz kierownikiem nowej placówki {$branchLabel} w siedzibie **{$headquarter?->name}** (organizacja **{$organizationName}**). Jej nazwę i dane podasz po przyjęciu zaproszenia.",
                $type !== null && $headquarter === null => 'Zostaniesz kierownikiem nowej siedziby '.($type === HeadquarterType::Shop ? 'sklepu' : 'hurtowni')." w organizacji **{$organizationName}**. Jej nazwę i dane podasz po przyjęciu zaproszenia.",
                $headquarter !== null => "Zostaniesz kierownikiem siedziby **{$headquarter->name}** (organizacja **{$organizationName}**).",
                (bool) $organizationName => "Zostaniesz właścicielem organizacji **{$organizationName}**.",
                default => 'Zostaniesz właścicielem nowej organizacji. Jej nazwę i dane podasz po przyjęciu zaproszenia.',
            })
            ->line('**Twój klucz zaproszenia:**')
            ->line("## {$this->verificationCode}")
            ->line('Klucz jest jednorazowy i potrzebny do przyjęcia zaproszenia. Nie udostępniaj go nikomu.')
            ->line('**Jak aktywować konto:**')
            ->line('1. Otwórz przycisk poniżej. Jeśli nie masz jeszcze konta, zostaniesz przeniesiony do rejestracji (adres e-mail jest już wypełniony), a jeśli masz, do logowania.')
            ->line('2. Po zalogowaniu wpisz klucz zaproszenia.')
            ->line($workerType !== null ? '3. Przejdź do panelu pracownika.' : ($isBranch ? '3. Uzupełnij dane placówki.' : ($type ? ($headquarter ? '3. Przejdź do panelu swojej siedziby.' : '3. Uzupełnij dane siedziby.') : '3. Uzupełnij dane organizacji.')))
            ->action('Przyjmij zaproszenie', route('organization-invitations.show', $this->invitation))
            ->line('Zaproszenie jest ważne do '.$this->invitation->expires_at->format('d.m.Y H:i').'.')
            ->line('Jeśli to nie Ty oczekiwałeś tego zaproszenia, zignoruj tę wiadomość.')
            ->salutation("Zespół {$platform}");
    }
}
