import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';

/** Powłoka panelu roli zalogowanego użytkownika (np. dla ustawień, zespołów i czatu). */
export default function RoleLayout({ children }: PropsWithChildren) {
    return <AdminLteShell>{children}</AdminLteShell>;
}
