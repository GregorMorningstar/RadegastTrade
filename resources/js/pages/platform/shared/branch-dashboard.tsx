import { Head } from '@inertiajs/react';
import { Building2, Store, Warehouse } from 'lucide-react';
import Barcode from 'react-barcode';
import { Card, Content, ContentHeader, SmallBox } from '@/components/adminlte/blocks';
import UserBarcodeCard from '@/components/user-barcode-card';

export type BranchDashboardProps = {
    branch: {
        id: number;
        name: string;
        barcode: string | null;
        NIP: string | null;
        organization: string;
        headquarter: { name: string; barcode: string | null };
    } | null;
};

function Code({ value, label }: { value: string | null; label: string }) {
    return (
        <div>
            <p className="mb-2 text-sm text-[#6c757d]">{label}</p>
            {value ? (
                <Barcode
                    value={value}
                    format="EAN13"
                    width={1.6}
                    height={50}
                    fontSize={12}
                    margin={0}
                    background="transparent"
                />
            ) : (
                '—'
            )}
        </div>
    );
}

/** Dashboard kierownika lub pracownika placówki (sklepu lub hurtowni) z kodami kreskowymi placówki i jej siedziby. */
export default function BranchDashboard({
    group,
    title,
    branch,
    panel = 'leader',
}: BranchDashboardProps & { group: 'shop' | 'warehouse'; title: string; panel?: 'leader' | 'worker' }) {
    const Icon = group === 'shop' ? Store : Warehouse;

    return (
        <>
            <Head title={title} />
            <ContentHeader
                title="Dashboard"
                crumbs={[{ label: 'Panel', href: `/${group}/${panel}` }, { label: 'Dashboard' }]}
            />
            <Content>
                {!branch ? (
                    <Card title="Dashboard" color="warning">
                        To konto nie ma przypisanej placówki.
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <SmallBox
                                color={group === 'shop' ? 'success' : 'info'}
                                value={branch.name}
                                label={group === 'shop' ? 'Placówka sklepu' : 'Placówka hurtowni'}
                                icon={Icon}
                            />
                            <SmallBox color="warning" value={branch.headquarter.name} label="Siedziba" icon={Icon} />
                            <SmallBox color="primary" value={branch.NIP ?? '—'} label="NIP" icon={Building2} />
                        </div>

                        <div className="mt-4 grid gap-4 lg:grid-cols-2">
                            <Card title={branch.name} color="primary">
                                <p className="mb-3 text-sm text-[#6c757d]">
                                    Organizacja: <strong>{branch.organization}</strong>
                                </p>
                                <Code value={branch.barcode} label="Kod kreskowy placówki" />
                            </Card>
                            <Card title={branch.headquarter.name} color="success">
                                <Code value={branch.headquarter.barcode} label="Kod kreskowy siedziby" />
                            </Card>
                        </div>
                    </>
                )}
                <UserBarcodeCard className="mt-4 max-w-md" />
            </Content>
        </>
    );
}
