import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import WarehouseHeadquartersTopMenuComponent from '@/pages/platform/admin/components/WarehouseHeadquartersTopMenuComponent';

export default function WarehouseHeadquarterIndex() {
    return (
        <>
            <Head title="Hurtownie - Główne siedziby" />
            <ContentHeader
                title="Główne siedziby"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Hurtownie' },
                    { label: 'Główne siedziby' },
                ]}
            />
            <Content>
                <div className="mb-3">
                    <WarehouseHeadquartersTopMenuComponent />
                </div>
                <Card title="Hurtownie - Główne siedziby" color="primary">
                    Tutaj pojawi się lista: Główne siedziby.
                </Card>
            </Content>
        </>
    );
}
