import type { PropsWithChildren } from 'react';
import RoleShell from '@/components/role/role-shell';

export default function ShopOwnerLayout({ children }: PropsWithChildren) {
    return (
        <RoleShell base="/shop/owner" brand="Sklep - kierownik">
            {children}
        </RoleShell>
    );
}
