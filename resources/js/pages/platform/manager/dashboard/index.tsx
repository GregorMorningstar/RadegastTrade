import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import UserBarcodeCard from '@/components/user-barcode-card';

export default function PlatformManagerDashboard() {
    return (
        <>
            <Head title="Dashboard - Manager" />
            <ContentHeader
                title="Dashboard"
                crumbs={[
                    { label: 'Platforma', href: '/platform/manager' },
                    { label: 'Dashboard' },
                ]}
            />
            <Content>
                <Card title="Dashboard - Manager" color="primary">
                    Tutaj pojawi się zawartość dashboardu.
                </Card>
                <UserBarcodeCard className="mt-4 max-w-md" />
            </Content>
        </>
    );
}
