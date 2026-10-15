import { Head } from '@inertiajs/react';
import { Content, ContentHeader } from '@/components/adminlte/blocks';
import HeadquartersTree from '@/pages/platform/admin/components/HeadquartersTree';

/** Preview of the manager's own headquarters: its contact and people, and its branches with their people. */
export default function HeadquarterStructurePage({ group }: { group: 'shop' | 'warehouse' }) {
    const base = `/${group}/owner`;
    const title = group === 'shop' ? 'Siedziba sklepu i placówki' : 'Siedziba hurtowni i placówki';

    return (
        <>
            <Head title={title} />
            <ContentHeader title={title} crumbs={[{ label: 'Panel', href: base }, { label: title }]} />
            <Content>
                <HeadquartersTree url={`${base}/structure/data`} type="mine" />
            </Content>
        </>
    );
}
