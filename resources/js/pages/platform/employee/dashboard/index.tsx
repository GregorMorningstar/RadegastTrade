import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import UserBarcodeCard from '@/components/user-barcode-card';

export default function PlatformEmployeeDashboard() {
    return (
        <>
            <Head title="Dashboard - Pracownik" />
            <ContentHeader
                title="Dashboard"
                crumbs={[
                    { label: 'Platforma', href: '/platform/employee' },
                    { label: 'Dashboard' },
                ]}
            />
            <Content>
                <Card title="Dashboard - Pracownik" color="primary">
                    Tutaj pojawi się zawartość dashboardu.
                </Card>
                <UserBarcodeCard className="mt-4 max-w-md" />
            </Content>
        </>
    );
}
