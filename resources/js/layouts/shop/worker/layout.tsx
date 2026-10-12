import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function ShopWorkerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/shop/worker">{children}</AdminLteShell>;
}
