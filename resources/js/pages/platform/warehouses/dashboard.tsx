import BranchDashboard from '@/pages/platform/shared/branch-dashboard';
import type { BranchDashboardProps } from '@/pages/platform/shared/branch-dashboard';

export default function WarehouseBranchDashboard(props: BranchDashboardProps) {
    return <BranchDashboard group="warehouse" title="Dashboard - Hurtownia - leader" {...props} />;
}
