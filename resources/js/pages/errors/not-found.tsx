import { SearchX } from 'lucide-react';
import RedirectCountdown from '@/components/redirect-countdown';

type Props = {
    redirectTo: string;
    destination: string;
};

export default function NotFound({ redirectTo, destination }: Props) {
    return (
        <RedirectCountdown
            title="Nie znaleziono strony (404)"
            description={`Taka strona nie istnieje. Zabieram Cię do: ${destination}.`}
            icon={SearchX}
            redirectTo={redirectTo}
        />
    );
}
