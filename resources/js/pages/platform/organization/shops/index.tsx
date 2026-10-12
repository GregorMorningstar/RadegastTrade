import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function OrganizationShops() {
    return (
        <>
            <Head title="Sklepy" />
            <ContentHeader
                title="Sklepy"
                crumbs={[
                    { label: 'Platforma', href: '/platform/organization' },
                    { label: 'Sklepy' },
                ]}
            />
            <Content>
                <Card title="Sklepy" color="primary">
                    Tutaj pojawi się lista sklepów. Ta sekcja jest w przygotowaniu.
                </Card>
            </Content>
        </>
    );
}
