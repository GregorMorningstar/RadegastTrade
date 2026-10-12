import HeadquarterDashboard from '@/pages/platform/shared/headquarter-dashboard';
import type { HeadquarterDashboardProps } from '@/pages/platform/shared/headquarter-dashboard';

export default function WarehouseHeadquarterDashboard(props: HeadquarterDashboardProps) {
    return <HeadquarterDashboard group="warehouse" title="Dashboard - Hurtownia - kierownik" {...props} />;
}
