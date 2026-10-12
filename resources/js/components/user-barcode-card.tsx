import { usePage } from '@inertiajs/react';
import Barcode from 'react-barcode';
import { Card } from '@/components/adminlte/blocks';

/** Karta z dużym kodem EAN-13 zalogowanego użytkownika (dla każdej roli, na dashboardzie). */
export default function UserBarcodeCard({ className }: { className?: string }) {
    const user = usePage().props.auth?.user;

    if (!user?.barcode) {
        return null;
    }

    return (
        <Card title="Twój kod kreskowy" color="info" className={className}>
            <p className="mb-3 text-sm text-[#6c757d]">
                {user.name} ({user.email})
            </p>
            <Barcode
                value={user.barcode}
                format="EAN13"
                width={1.6}
                height={50}
                fontSize={12}
                margin={0}
                background="transparent"
            />
        </Card>
    );
}
