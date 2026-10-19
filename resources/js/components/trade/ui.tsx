import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export const btn = 'rounded bg-[#007bff] px-3 py-1.5 text-sm text-white hover:bg-[#0069d9] disabled:opacity-50';
export const btnOutline =
    'rounded border border-[#007bff] px-3 py-1.5 text-sm text-[#007bff] hover:bg-[#007bff] hover:text-white disabled:opacity-50';
export const btnDanger =
    'rounded border border-[#dc3545] px-3 py-1.5 text-sm text-[#dc3545] hover:bg-[#dc3545] hover:text-white disabled:opacity-50';
export const inputCls = 'w-full rounded border border-[#ced4da] bg-white px-2 py-1.5 text-sm';
export const th = 'py-2 pr-4 font-semibold';
export const td = 'py-2 pr-4 align-top';

const colors: Record<string, string> = {
    draft: 'bg-[#6c757d]',
    published: 'bg-[#28a745]',
    withdrawn: 'bg-[#fd7e14]',
    expired: 'bg-[#dc3545]',
    archived: 'bg-[#6c757d]',
    active: 'bg-[#28a745]',
    suspended: 'bg-[#fd7e14]',
    ended: 'bg-[#6c757d]',
    pending: 'bg-[#17a2b8]',
    accepted: 'bg-[#28a745]',
    rejected: 'bg-[#dc3545]',
    cancelled: 'bg-[#6c757d]',
    submitted: 'bg-[#17a2b8]',
    confirmed: 'bg-[#007bff]',
    fulfilled: 'bg-[#6f42c1]',
    received: 'bg-[#28a745]',
};

export const offerStatusLabels: Record<string, string> = {
    draft: 'Szkic',
    published: 'Opublikowana',
    withdrawn: 'Wycofana',
    expired: 'Wygasła',
    archived: 'Zarchiwizowana',
};

export function Badge({ status, label }: { status: string; label?: string }) {
    return (
        <span className={`rounded px-2 py-0.5 text-xs text-white ${colors[status] ?? 'bg-[#6c757d]'}`}>
            {label ?? offerStatusLabels[status] ?? status}
        </span>
    );
}

export function money(value: string | number | null | undefined, currency = 'PLN'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency }).format(Number(value));
}

export function date(iso: string | null | undefined): string {
    return iso ? new Date(iso).toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
}

/** Server errors (validation and business rules) shown in one place above the content. */
export function Errors() {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const messages = Object.values(errors);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div role="alert" className="mb-4 rounded border border-[#f5c6cb] bg-[#f8d7da] px-4 py-3 text-sm text-[#721c24]">
            {messages.map((message, index) => (
                <p key={index}>{message}</p>
            ))}
        </div>
    );
}

export function Empty({ children }: PropsWithChildren) {
    return <p className="py-4 text-sm text-[#6c757d]">{children}</p>;
}

/** Tabs of the trade module, shown at the top of every page of it. */
export function TradeTabs({ current }: { current: 'offers' | 'available' | 'orders' | 'partners' | 'permissions' }) {
    const tabs: { key: typeof current; href: string; label: string }[] = [
        { key: 'available', href: '/trade/available', label: 'Oferty dostępne' },
        { key: 'offers', href: '/trade/offers', label: 'Moje oferty' },
        { key: 'orders', href: '/trade/orders', label: 'Zamówienia' },
        { key: 'partners', href: '/trade/partners', label: 'Współpraca' },
        { key: 'permissions', href: '/trade/permissions', label: 'Uprawnienia jednostki' },
    ];

    return (
        <nav className="mb-4 flex flex-wrap gap-1 border-b border-[#dee2e6]">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                        tab.key === current ? 'border-[#007bff] text-[#007bff]' : 'border-transparent text-[#495057] hover:text-[#007bff]'
                    }`}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
