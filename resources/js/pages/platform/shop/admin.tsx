import AdminStructureList from '@/pages/platform/shared/structure-list';
import type { StructureListProps } from '@/pages/platform/shared/structure-list';
import ShopHeadquartersTopMenuComponent from '@/pages/platform/admin/components/ShopHeadquartersTopMenuComponent';

export default function ShopBranchAdminList(props: StructureListProps) {
    return (
        <AdminStructureList
            title="Placówki"
            section="Sklepy"
            singular="placówek sklepów"
            topMenu={<ShopHeadquartersTopMenuComponent />}
            {...props}
        />
    );
}
