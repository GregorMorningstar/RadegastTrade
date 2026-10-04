import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AdminLteShell from '@/components/adminlte/adminlte-shell';
import RoleShell from '@/components/role/role-shell';

/** Powłoka panelu roli zalogowanego użytkownika (np. dla ustawień i zespołów). */
export default function RoleLayout({ children }: PropsWithChildren) {
    const { rolePanel } = usePage().props;

    // Grupa "platform" ma wygląd AdminLTE, pozostałe grupy – ciemne menu z kafelkami
    return rolePanel?.base.startsWith('/platform') ? (
        <AdminLteShell>{children}</AdminLteShell>
    ) : (
        <RoleShell>{children}</RoleShell>
    );
}
