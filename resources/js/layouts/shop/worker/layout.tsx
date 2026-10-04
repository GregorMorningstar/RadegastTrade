import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function ShopWorkerLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/shop/worker" brand="Sklep - pracownik">
            {children}
        </RoleShell>
    );
}
