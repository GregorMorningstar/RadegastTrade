import { Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

const base = '/platform/admin';

const tabs = [
    { title: 'Organizacje', href: `${base}/organizations` },
    { title: 'Hurtownie', href: `${base}/warehouses/my-headquarters` },
    { title: 'Sklepy', href: `${base}/shops/my-headquarters` },
];

/** Górne menu strony Organizacje (zakładki i akcja dodawania). */
export default function OrganizationTopMenuComponent() {
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded bg-white px-2 shadow-sm">
            <nav className="flex">
                {tabs.map((tab) => (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            'border-b-2 border-transparent px-4 py-3 text-sm text-[#6c757d] transition-colors hover:text-[#212529]',
                            path === tab.href &&
                                'border-[#007bff] font-semibold text-[#007bff]',
                        )}
                    >
                        {tab.title}
                    </Link>
                ))}
            </nav>

            <Link
                href={`${base}/organizations/create`}
                className="flex items-center gap-1 rounded bg-[#28a745] px-3 py-1.5 text-sm text-white hover:bg-[#218838]"
            >
                <Plus className="size-4" />
                Dodaj organizację
            </Link>
        </div>
    );
}
