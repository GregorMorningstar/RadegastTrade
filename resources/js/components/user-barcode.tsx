import { usePage } from '@inertiajs/react';
import Barcode from 'react-barcode';

/** Kod EAN-13 zalogowanego użytkownika (karta pracownika), do wyświetlania w nagłówku. */
export default function UserBarcode({ className }: { className?: string }) {
    const barcode = usePage().props.auth?.user?.barcode;

    if (!barcode) {
        return null;
    }

    return (
        <div className={className} title={`Kod użytkownika: ${barcode}`}>
            <Barcode
                value={barcode}
                format="EAN13"
                width={1}
                height={26}
                fontSize={11}
                margin={0}
                background="transparent"
            />
        </div>
    );
}
