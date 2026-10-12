import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function ShopBranchIndex() {
    return (
        <>
            <Head title="Sklepy - Placówki" />
            <ContentHeader
                title="Placówki"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Sklepy' },
                    { label: 'Placówki' },
                ]}
            />
            <Content>
                <Card title="Sklepy - Placówki" color="primary">
                    Tutaj pojawi się lista: Placówki.
                </Card>
            </Content>
        </>
    );
}
