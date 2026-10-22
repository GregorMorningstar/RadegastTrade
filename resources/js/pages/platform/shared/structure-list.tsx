import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Barcode from 'react-barcode';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

export type StructureRow = {
    id: number;
    name: string;
    organization: string;
    /** Tylko placówki: siedziba, do której należą */
    headquarter?: string;
    barcode: string | null;
    NIP: string | null;
    address: string;
    is_active: boolean;
    managers: { id: number; name: string; email: string }[];
};

export type StructureListProps = {
    rows: StructureRow[];
};

/** Lista siedzib albo placówek wszystkich organizacji (widok administratora, tylko do odczytu). */
export default function AdminStructureList({
    title,
    section,
    singular,
    topMenu,
    rows,
}: StructureListProps & {
    /** Nagłówek strony, np. "Główne siedziby" */
    title: string;
    /** Sekcja w okruszkach, np. "Sklepy" */
    section: string;
    /** Co wylistowano w dopełniaczu liczby mnogiej, np. "siedzib" */
    singular: string;
    topMenu: ReactNode;
}) {
    const hasHeadquarter = rows.some((row) => row.headquarter !== undefined);

    return (
        <>
            <Head title={`${section} - ${title}`} />
            <ContentHeader
                title={title}
                crumbs={[
                    { label: 'Platforma', href: '/platform/admin' },
                    { label: section },
                    { label: title },
                ]}
            />
            <Content>
                <div className="mb-3">{topMenu}</div>

                <Card title={`${section} - ${title}`} color="primary">
                    {rows.length === 0 ? (
                        <p className="text-sm text-[#6c757d]">Nie ma jeszcze żadnych {singular}.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-[#dee2e6]">
                                        <th className="py-2 pr-4 font-semibold">Nazwa</th>
                                        <th className="py-2 pr-4 font-semibold">Organizacja</th>
                                        {hasHeadquarter && (
                                            <th className="py-2 pr-4 font-semibold">Siedziba</th>
                                        )}
                                        <th className="py-2 pr-4 font-semibold">NIP</th>
                                        <th className="py-2 pr-4 font-semibold">Adres</th>
                                        <th className="py-2 pr-4 font-semibold">Kierownicy</th>
                                        <th className="py-2 pr-4 font-semibold">Status</th>
                                        <th className="py-2 font-semibold">Kod</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-b border-[#dee2e6] align-top last:border-0">
                                            <td className="py-2 pr-4 font-medium">{row.name}</td>
                                            <td className="py-2 pr-4">{row.organization}</td>
                                            {hasHeadquarter && <td className="py-2 pr-4">{row.headquarter ?? 'bez siedziby'}</td>}
                                            <td className="py-2 pr-4">{row.NIP ?? '—'}</td>
                                            <td className="py-2 pr-4">{row.address || '—'}</td>
                                            <td className="py-2 pr-4">
                                                {row.managers.length === 0
                                                    ? '—'
                                                    : row.managers.map((m) => (
                                                          <div key={m.id}>
                                                              {m.name}{' '}
                                                              <span className="text-[#6c757d]">({m.email})</span>
                                                          </div>
                                                      ))}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <span
                                                    className={`rounded px-2 py-0.5 text-xs text-white ${row.is_active ? 'bg-[#28a745]' : 'bg-[#6c757d]'}`}
                                                >
                                                    {row.is_active ? 'aktywna' : 'nieaktywna'}
                                                </span>
                                            </td>
                                            <td className="py-2">
                                                {row.barcode ? (
                                                    <Barcode
                                                        value={row.barcode}
                                                        format="EAN13"
                                                        width={1}
                                                        height={28}
                                                        fontSize={10}
                                                        margin={0}
                                                        background="transparent"
                                                    />
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </Content>
        </>
    );
}
