import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function PlatformAdminLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/platform/admin">{children}</AdminLteShell>;
}
