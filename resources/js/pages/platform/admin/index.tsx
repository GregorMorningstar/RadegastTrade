import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function PlatformAdminIndex() {
    return (
        <>
            <Head title="Panel - Administrator" />
            <ContentHeader
                title="Panel"
                crumbs={[{ label: 'Platforma' }, { label: 'Administrator' }]}
            />
            <Content>
                <Card title="Panel - Administrator" color="primary">
                    Tutaj pojawi się zawartość panelu.
                </Card>
            </Content>
        </>
    );
}
