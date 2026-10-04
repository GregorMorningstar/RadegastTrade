import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function PlatformManagerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/platform/manager">{children}</AdminLteShell>;
}
