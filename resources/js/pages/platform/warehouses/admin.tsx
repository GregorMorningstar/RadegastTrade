import AdminStructureList from '@/pages/platform/shared/structure-list';
import type { StructureListProps } from '@/pages/platform/shared/structure-list';
import WarehouseHeadquartersTopMenuComponent from '@/pages/platform/admin/components/WarehouseHeadquartersTopMenuComponent';

export default function WarehouseBranchAdminList(props: StructureListProps) {
    return (
        <AdminStructureList
            title="Placówki"
            section="Hurtownie"
            singular="placówek hurtowni"
            topMenu={<WarehouseHeadquartersTopMenuComponent />}
            {...props}
        />
    );
}
