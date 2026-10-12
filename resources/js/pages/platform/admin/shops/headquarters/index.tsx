import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import ShopHeadquartersTopMenuComponent from '@/pages/platform/admin/components/ShopHeadquartersTopMenuComponent';

export default function ShopHeadquarterIndex() {
    return (
        <>
            <Head title="Sklepy - Główne siedziby" />
            <ContentHeader
                title="Główne siedziby"
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: 'Sklepy' },
                    { label: 'Główne siedziby' },
                ]}
            />
            <Content>
                <div className="mb-3">
                   <ShopHeadquartersTopMenuComponent />
                </div>
                <Card title="Sklepy - Główne siedziby" color="primary">
                    Tutaj pojawi się lista: Główne siedziby.
                </Card>
            </Content>
        </>
    );
}
