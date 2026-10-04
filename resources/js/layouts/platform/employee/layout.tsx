import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function PlatformEmployeeLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/platform/employee">{children}</AdminLteShell>;
}
