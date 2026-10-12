import AdminStructureList from '@/pages/platform/shared/structure-list';
import type { StructureListProps } from '@/pages/platform/shared/structure-list';
import WarehouseHeadquartersTopMenuComponent from '@/pages/platform/admin/components/WarehouseHeadquartersTopMenuComponent';

export default function WarehouseHeadquarterAdminList(props: StructureListProps) {
    return (
        <AdminStructureList
            title="Główne siedziby"
            section="Hurtownie"
            singular="siedzib hurtowni"
            topMenu={<WarehouseHeadquartersTopMenuComponent />}
            {...props}
        />
    );
}
