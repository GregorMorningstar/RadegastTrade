import HeadquarterSetupForm from '@/pages/platform/shared/headquarter-setup';

type Props = { email: string; phone: string | null; organizationName: string; organizationNip: string | null };

export default function ShopHeadquarterSetup(props: Props) {
    return <HeadquarterSetupForm group="shop" {...props} />;
}
