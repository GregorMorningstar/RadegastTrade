import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function WarehouseOwnerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/warehouse/owner">{children}</AdminLteShell>;
}
