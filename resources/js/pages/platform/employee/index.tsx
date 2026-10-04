import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function PlatformEmployeeIndex() {
    return (
        <>
            <Head title="Panel - Pracownik" />
            <ContentHeader
                title="Panel"
                crumbs={[{ label: 'Platforma' }, { label: 'Pracownik' }]}
            />
            <Content>
                <Card title="Panel - Pracownik" color="primary">
                    Tutaj pojawi się zawartość panelu.
                </Card>
            </Content>
        </>
    );
}
