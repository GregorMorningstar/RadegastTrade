import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

export default function OrganizationOwnerLayout({ children }: PropsWithChildren) {
    return <AdminLteShell base="/platform/organization">{children}</AdminLteShell>;
}
