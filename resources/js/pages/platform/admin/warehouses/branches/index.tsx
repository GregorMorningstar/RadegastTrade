import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function WarehouseBranchIndex() {
    return (
        <>
            <Head title="Hurtownie - Placówki" />
            <ContentHeader
                title="Placówki"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Hurtownie' },
                    { label: 'Placówki' },
                ]}
            />
            <Content>
                <Card title="Hurtownie - Placówki" color="primary">
                    Tutaj pojawi się lista: Placówki.
                </Card>
            </Content>
        </>
    );
}
