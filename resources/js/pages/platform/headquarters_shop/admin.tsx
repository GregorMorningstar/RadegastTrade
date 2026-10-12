import AdminStructureList from '@/pages/platform/shared/structure-list';
import type { StructureListProps } from '@/pages/platform/shared/structure-list';
import ShopHeadquartersTopMenuComponent from '@/pages/platform/admin/components/ShopHeadquartersTopMenuComponent';

export default function ShopHeadquarterAdminList(props: StructureListProps) {
    return (
        <AdminStructureList
            title="Główne siedziby"
            section="Sklepy"
            singular="siedzib sklepów"
            topMenu={<ShopHeadquartersTopMenuComponent />}
            {...props}
        />
    );
}
