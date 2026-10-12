import HeadquarterDashboard from '@/pages/platform/shared/headquarter-dashboard';
import type { HeadquarterDashboardProps } from '@/pages/platform/shared/headquarter-dashboard';

export default function ShopHeadquarterDashboard(props: HeadquarterDashboardProps) {
    return <HeadquarterDashboard group="shop" title="Dashboard - Sklep - kierownik" {...props} />;
}
