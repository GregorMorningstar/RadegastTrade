import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

type Props = {
    organization: {
        id: number;
        name: string;
        barcode: string | null;
        NIP: string | null;
        REGON: string | null;
        email: string | null;
        phone: string | null;
        street: string | null;
        building_number: string | null;
        apartment_number: string | null;
        postal_code: string | null;
        city: string | null;
        country: string | null;
        is_active: boolean;
    };
};

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <>
            <dt className="text-[#6c757d]">{label}</dt>
            <dd>{value || '—'}</dd>
        </>
    );
}

export default function OrganizationOwnerIndex({ organization }: Props) {
    const street = [
        organization.street,
        [organization.building_number, organization.apartment_number]
            .filter(Boolean)
            .join('/'),
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <>
            <Head title="Panel - Właściciel organizacji" />
            <ContentHeader
                title="Panel"
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: 'Panel' },
                ]}
            />

            <Content>
                <Card title="Dane organizacji" color="primary">
                    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                        <Row label="Nazwa" value={organization.name} />
                        <Row label="NIP" value={organization.NIP} />
                        <Row label="REGON" value={organization.REGON} />
                        <Row label="E-mail" value={organization.email} />
                        <Row label="Telefon" value={organization.phone} />
                        <Row label="Adres" value={street} />
                        <Row
                            label="Miejscowość"
                            value={[organization.postal_code, organization.city]
                                .filter(Boolean)
                                .join(' ')}
                        />
                        <Row label="Kraj" value={organization.country} />
                        <Row label="Kod kreskowy" value={organization.barcode} />
                        <Row
                            label="Status"
                            value={organization.is_active ? 'Aktywna' : 'Nieaktywna'}
                        />
                    </dl>
                </Card>
            </Content>
        </>
    );
}
