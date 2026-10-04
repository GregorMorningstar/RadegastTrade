import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function WarehouseLeaderLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/warehouse/leader" brand="Hurtownia - leader">
            {children}
        </RoleShell>
    );
}
