import BranchDashboard from '@/pages/platform/shared/branch-dashboard';
import type { BranchDashboardProps } from '@/pages/platform/shared/branch-dashboard';

export default function WarehouseWorkerDashboard(props: BranchDashboardProps) {
    return <BranchDashboard group="warehouse" panel="worker" title="Dashboard - Hurtownia - pracownik" {...props} />;
}
