import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function OrganizationWarehouses() {
    return (
        <>
            <Head title="Hurtownie" />
            <ContentHeader
                title="Hurtownie"
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: 'Hurtownie' },
                ]}
            />
            <Content>
                <Card title="Hurtownie" color="primary">
                    Tutaj pojawi się lista hurtowni. Ta sekcja jest w przygotowaniu.
                </Card>
            </Content>
        </>
    );
}
