import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function WarehouseOwnerLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/warehouse/owner" brand="Hurtownia - kierownik">
            {children}
        </RoleShell>
    );
}
