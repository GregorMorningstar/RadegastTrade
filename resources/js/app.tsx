import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { setupEcho } from '@/lib/echo';
import AppLayout from '@/layouts/app-layout';
import PlatformAdminLayout from '@/layouts/platform/admin/layout';
import WarehouseOwnerLayout from '@/layouts/warehouse/owner/layout';
import WarehouseLeaderLayout from '@/layouts/warehouse/leader/layout';
import WarehouseWorkerLayout from '@/layouts/warehouse/worker/layout';
import ShopOwnerLayout from '@/layouts/shop/owner/layout';
import ShopLeaderLayout from '@/layouts/shop/leader/layout';
import ShopWorkerLayout from '@/layouts/shop/worker/layout';
import OrganizationOwnerLayout from '@/layouts/platform/organization/layout';
import AuthLayout from '@/layouts/auth-layout';
import LegalLayout from '@/layouts/legal-layout';
import RoleLayout from '@/layouts/role-layout';
import SettingsLayout from '@/layouts/settings/layout';

const roleLayouts: Record<string, React.ComponentType<{ children: React.ReactNode }>> = {
    'platform/admin': PlatformAdminLayout,
    'platform/warehouses/owner': WarehouseOwnerLayout,
    'platform/warehouses/leader': WarehouseLeaderLayout,
    'platform/warehouses/worker': WarehouseWorkerLayout,
    'platform/shop/owner': ShopOwnerLayout,
    'platform/shop/leader': ShopLeaderLayout,
    'platform/shop/worker': ShopWorkerLayout,
    'platform/organization': OrganizationOwnerLayout,
};

// Klucz layoutu: najpierw trzy pierwsze segmenty (platform/shop/owner), potem dwa (platform/admin)
function roleLayoutKey(name: string): string | null {
    const parts = name.split('/');

    return [parts.slice(0, 3).join('/'), parts.slice(0, 2).join('/')].find((key) => key in roleLayouts) ?? null;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

setupEcho();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('account/'):
                return null;
            case name.startsWith('legal/'):
                return LegalLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('chat/'):
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [RoleLayout, SettingsLayout];
            case roleLayoutKey(name) !== null:
                // własny wygląd paneli ról (ciemne menu), bez wspólnego AppLayout
                return roleLayouts[roleLayoutKey(name) as string];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
