/** Badge colours of the roles (Tailwind classes), shared by the team view and the structure views. */
export const roleColors: Record<string, string> = {
    organization_owner: 'bg-[#6f42c1] text-white',
    shop_owner: 'bg-[#28a745] text-white',
    warehouse_owner: 'bg-[#17a2b8] text-white',
    shop_leader: 'bg-[#20c997] text-[#1f2d3d]',
    warehouse_leader: 'bg-[#6610f2] text-white',
    shop_worker: 'bg-[#fd7e14] text-white',
    warehouse_worker: 'bg-[#e83e8c] text-white',
};

export const defaultRoleColor = 'bg-[#6c757d] text-white';

export function roleColor(role: string | undefined): string {
    return (role && roleColors[role]) || defaultRoleColor;
}
