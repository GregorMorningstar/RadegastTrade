<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * E-mail with a newly generated API key of a connector (the only place where the key is shown).
 */
class ApiKeyNotification extends Notification
{
    public function __construct(
        #[SensitiveParameter] public string $key,
        public string $unitName,
        public string $connectorName,
        public string $apiUrl,
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
        return (new MailMessage)
            ->subject('Klucz API konektora „'.$this->connectorName.'” – '.OrganizationInvitationNotification::PLATFORM)
            ->greeting('Dzień dobry '.$notifiable->name.',')
            ->line('Wygenerowaliśmy klucz API dla konektora „'.$this->connectorName.'” (jednostka: '.$this->unitName.'). Pokazujemy go tylko w tej wiadomości, w bazie zapisany jest wyłącznie jego skrót.')
            ->line('Adres serwera (adres API w konektorze): **'.$this->apiUrl.'**')
            ->line('Klucz API: **'.$this->key.'**')
            ->line('Wpisz je w programie ApiConnector na komputerze tej jednostki: wybierz „Klucz API w nagłówku” (X-API-KEY) albo „Bearer Token” i wklej klucz. Ten sam klucz możesz wpisać na kilku komputerach jednostki; dane zawsze trafią do tej jednostki. Przechowuj klucz w bezpiecznym miejscu i nie przekazuj nikomu.')
            ->line('Jednostka ma jeden aktywny klucz: aby wygenerować nowy, trzeba usunąć ten w ustawieniach konta.');
    }
}
