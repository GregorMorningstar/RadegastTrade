import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function WarehouseWorkerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/warehouse/worker">{children}</AdminLteShell>;
}
