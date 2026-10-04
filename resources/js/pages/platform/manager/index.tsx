import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export default function PlatformManagerIndex() {
    return (
        <>
            <Head title="Panel - Manager" />
            <ContentHeader
                title="Panel"
                crumbs={[{ label: 'Platforma' }, { label: 'Manager' }]}
            />
            <Content>
                <Card title="Panel - Manager" color="primary">
                    Tutaj pojawi się zawartość panelu.
                </Card>
            </Content>
        </>
    );
}
