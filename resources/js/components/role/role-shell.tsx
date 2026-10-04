import { Link, usePage } from '@inertiajs/react';
import {
    LayoutDashboard,
    LayoutGrid,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    UserCircle,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren } from 'react';
import UserBarcode from '@/components/user-barcode';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

type MenuItem = {
    title: string;
    href: string;
    icon: LucideIcon;
};

type Props = PropsWithChildren<{
    /** Adres bazowy panelu roli; domyślnie panel zalogowanego użytkownika */
    base?: string;
    /** Nazwa na górze menu; domyślnie nazwa panelu zalogowanego użytkownika */
    brand?: string;
}>;

const STORAGE_KEY = 'role-sidebar-collapsed';

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export default function RoleShell({
    base: baseProp,
    brand: brandProp,
    children,
}: Props) {
    const { url, props } = usePage();
    const base = baseProp ?? props.rolePanel?.base ?? '/';
    const brand = brandProp ?? props.rolePanel?.name ?? '';
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

    useEffect(() => setCollapsed(readCollapsed()), []);

    const toggleCollapsed = () => {
        setCollapsed((value) => {
            try {
                localStorage.setItem(STORAGE_KEY, value ? '0' : '1');
            } catch {
                // brak dostępu do localStorage – stan tylko na czas życia strony
            }

            return !value;
        });
    };

    const items: MenuItem[] = [
        { title: 'Dashboard', href: `${base}/dashboard`, icon: LayoutDashboard },
        { title: 'Panel', href: base, icon: LayoutGrid },
        { title: 'Profil', href: editProfile().url, icon: UserCircle },
    ];

    const isActive = (href: string) => {
        const path = url.split('?')[0];

        return href === base ? path === base : path.startsWith(href);
    };

    // Napisy chowają się płynnie: zerowa szerokość i przezroczystość zamiast display:none
    const label = cn(
        'overflow-hidden whitespace-nowrap transition-all duration-300',
        collapsed ? 'lg:max-w-0 lg:opacity-0' : 'max-w-40 opacity-100',
    );

    const itemClass = cn(
        'flex items-center gap-3 px-5 py-3 transition-colors hover:text-white',
        collapsed && 'lg:justify-center lg:gap-0 lg:px-0',
    );

    return (
        <div className="flex min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-40 flex w-56 flex-col overflow-hidden rounded-r-2xl bg-slate-800 text-sm text-slate-300 transition-all duration-300 lg:sticky lg:top-0 lg:m-1 lg:h-[calc(100vh-0.5rem)] lg:translate-x-0 lg:rounded-2xl',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    collapsed ? 'lg:w-16' : 'lg:w-56',
                )}
            >
                <div className="flex h-14 items-center justify-center bg-slate-900 font-bold text-white">
                    <span className={label}>{brand}</span>
                    <span className={cn('hidden', collapsed && 'lg:block')}>
                        {brand.charAt(0)}
                    </span>
                </div>

                <nav className="flex-1 py-1">
                    {items.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            title={item.title}
                            onClick={() => setMobileOpen(false)}
                            className={cn(
                                itemClass,
                                isActive(item.href) && 'font-bold text-white',
                            )}
                        >
                            <item.icon className="size-4 shrink-0" />
                            <span className={label}>{item.title}</span>
                        </Link>
                    ))}
                </nav>

                <Link
                    href={logout()}
                    as="button"
                    title="Wyloguj"
                    className={cn(
                        itemClass,
                        'w-full bg-blue-600 text-left text-white hover:bg-blue-500',
                    )}
                >
                    <LogOut className="size-4 shrink-0" />
                    <span className={label}>Wyloguj</span>
                </Link>
            </aside>

            {mobileOpen && (
                <button
                    type="button"
                    aria-label="Zamknij menu"
                    className="fixed inset-0 z-30 bg-black/40 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center gap-3 px-5">
                    <button
                        type="button"
                        className="lg:hidden"
                        aria-label="Menu"
                        onClick={() => setMobileOpen((v) => !v)}
                    >
                        {mobileOpen ? (
                            <X className="size-5" />
                        ) : (
                            <Menu className="size-5" />
                        )}
                    </button>
                    <button
                        type="button"
                        className="hidden text-slate-500 hover:text-slate-900 lg:block dark:hover:text-white"
                        aria-label={collapsed ? 'Rozwiń menu' : 'Zwiń menu'}
                        onClick={toggleCollapsed}
                    >
                        {collapsed ? (
                            <PanelLeftOpen className="size-5" />
                        ) : (
                            <PanelLeftClose className="size-5" />
                        )}
                    </button>
                    <span className="hidden text-sm text-slate-500 md:block">
                        Szukaj (ctrl+k)
                    </span>
                    <span className="ml-auto text-sm text-slate-500">
                        {props.auth?.user?.name}
                    </span>
                    <UserBarcode className="shrink-0" />
                </header>

                <main className="flex-1 px-5 pb-6">{children}</main>
            </div>
        </div>
    );
}
