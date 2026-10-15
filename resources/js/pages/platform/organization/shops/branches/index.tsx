import OrganizationBranches from '@/pages/platform/shared/organization-branches';

type Props = Parameters<typeof OrganizationBranches>[0];

export default function ShopsBranches(props: Props) {
    return <OrganizationBranches {...props} />;
}
