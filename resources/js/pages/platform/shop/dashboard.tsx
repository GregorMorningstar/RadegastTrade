import BranchDashboard from '@/pages/platform/shared/branch-dashboard';
import type { BranchDashboardProps } from '@/pages/platform/shared/branch-dashboard';

export default function ShopBranchDashboard(props: BranchDashboardProps) {
    return <BranchDashboard group="shop" title="Dashboard - Sklep - leader" {...props} />;
}
