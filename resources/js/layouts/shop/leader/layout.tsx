import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function ShopLeaderLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/shop/leader" brand="Sklep - leader">
            {children}
        </RoleShell>
    );
}
