import BranchDashboard from '@/pages/platform/shared/branch-dashboard';
import type { BranchDashboardProps } from '@/pages/platform/shared/branch-dashboard';

export default function ShopWorkerDashboard(props: BranchDashboardProps) {
    return <BranchDashboard group="shop" panel="worker" title="Dashboard - Sklep - pracownik" {...props} />;
}
