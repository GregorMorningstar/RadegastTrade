import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import PlatformAdminLayout from '@/layouts/platform/admin/layout';
import PlatformManagerLayout from '@/layouts/platform/manager/layout';
import PlatformEmployeeLayout from '@/layouts/platform/employee/layout';
import WarehouseOwnerLayout from '@/layouts/warehouse/owner/layout';
import WarehouseLeaderLayout from '@/layouts/warehouse/leader/layout';
import WarehouseWorkerLayout from '@/layouts/warehouse/worker/layout';
import ShopOwnerLayout from '@/layouts/shop/owner/layout';
import ShopLeaderLayout from '@/layouts/shop/leader/layout';
import ShopWorkerLayout from '@/layouts/shop/worker/layout';
import AuthLayout from '@/layouts/auth-layout';
import RoleLayout from '@/layouts/role-layout';
import SettingsLayout from '@/layouts/settings/layout';

const roleLayouts: Record<string, React.ComponentType<{ children: React.ReactNode }>> = {
    'platform/admin': PlatformAdminLayout,
    'platform/manager': PlatformManagerLayout,
    'platform/employee': PlatformEmployeeLayout,
    'warehouse/owner': WarehouseOwnerLayout,
    'warehouse/leader': WarehouseLeaderLayout,
    'warehouse/worker': WarehouseWorkerLayout,
    'shop/owner': ShopOwnerLayout,
    'shop/leader': ShopLeaderLayout,
    'shop/worker': ShopWorkerLayout,
};

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [RoleLayout, SettingsLayout];
            case name.split('/').slice(0, 2).join('/') in roleLayouts:
                // własny wygląd paneli ról (ciemne menu), bez wspólnego AppLayout
                return roleLayouts[name.split('/').slice(0, 2).join('/')];
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
