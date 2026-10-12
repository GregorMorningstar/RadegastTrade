import { Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

const base = '/platform/admin/shops';

const tabs = [
    { title: 'Główne siedziby', href: `${base}/headquarters` },
    { title: 'Placówki', href: `${base}/branches` },
];

/** Górne menu strony Sklepy → Główne siedziby. */
export default function ShopHeadquartersTopMenuComponent() {
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

            {/* Akcję dodawania podepniemy, gdy powstaną formularz i trasa zapisu */}
            <button
                type="button"
                disabled
                title="Dodaj siedzibę sklepu – dostępne po dodaniu formularza"
                className="flex items-center gap-1 rounded bg-[#28a745] px-3 py-1.5 text-sm text-white opacity-60"
            >
                <Plus className="size-4" />
                Dodaj
            </button>
        </div>
    );
}
