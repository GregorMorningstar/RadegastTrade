import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function WarehouseLeaderLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/warehouse/leader">{children}</AdminLteShell>;
}
