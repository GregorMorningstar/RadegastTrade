import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function WarehouseWorkerLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/warehouse/worker" brand="Hurtownia - pracownik">
            {children}
        </RoleShell>
    );
}
