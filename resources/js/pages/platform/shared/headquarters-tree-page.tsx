import { Head } from '@inertiajs/react';
import { Content, ContentHeader } from '@/components/adminlte/blocks';
import HeadquartersTree from '@/pages/platform/admin/components/HeadquartersTree';
import OrganizationTopMenuComponent from '@/pages/platform/admin/components/OrganizationTopMenuComponent';

/** Page with the tree of all shop or warehouse headquarters, their organizations, branches and people. */
export default function HeadquartersTreePage({ type }: { type: 'shops' | 'warehouses' }) {
    const title = type === 'shops' ? 'Sklepy' : 'Hurtownie';

    return (
        <>
            <Head title={title} />
            <ContentHeader title={title} crumbs={[{ label: 'Platforma', href: '/platform/admin' }, { label: title }]} />
            <Content>
                <div className="mb-3">
                    <OrganizationTopMenuComponent />
                </div>
                <HeadquartersTree url={`/platform/headquarters/${type}/tree`} type={type} />
            </Content>
        </>
    );
}
