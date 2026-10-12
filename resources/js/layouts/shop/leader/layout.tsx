import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function ShopLeaderLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/shop/leader">{children}</AdminLteShell>;
}
