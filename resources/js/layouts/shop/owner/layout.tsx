import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function ShopOwnerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/shop/owner">{children}</AdminLteShell>;
}
