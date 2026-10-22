import HeadquarterSetupForm from '@/pages/platform/shared/headquarter-setup';

type Props = {
    email: string;
    phone: string | null;
    organizationName: string;
    headquarterName: string | null;
    organizationNip: string | null;
};

export default function ShopBranchSetup(props: Props) {
    return <HeadquarterSetupForm group="shop" branch {...props} />;
}
