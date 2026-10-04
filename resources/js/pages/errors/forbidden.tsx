import { ShieldAlert } from 'lucide-react';
import RedirectCountdown from '@/components/redirect-countdown';

type Props = {
    redirectTo: string;
    destination: string;
};

export default function Forbidden({ redirectTo, destination }: Props) {
    return (
        <RedirectCountdown
            title="Brak uprawnień"
            description={`Nie masz dostępu do tej strony. Zabieram Cię do: ${destination}.`}
            icon={ShieldAlert}
            redirectTo={redirectTo}
        />
    );
}
