import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ArrowRightCircle } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Crumb = { label: string; href?: string };

/** Nagłówek treści: tytuł po lewej i okruszki po prawej (jak w AdminLTE). */
export function ContentHeader({
    title,
    crumbs = [],
}: {
    title: string;
    crumbs?: Crumb[];
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-4">
            <h1 className="text-2xl font-normal">{title}</h1>
            <ol className="flex items-center gap-1 text-sm text-[#6c757d]">
                {crumbs.map((crumb, i) => (
                    <li key={crumb.label} className="flex items-center gap-1">
                        {i > 0 && <span>/</span>}
                        {crumb.href ? (
                            <Link href={crumb.href} className="text-[#007bff] hover:underline">
                                {crumb.label}
                            </Link>
                        ) : (
                            <span>{crumb.label}</span>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

/** Obszar treści z odstępami. */
export function Content({ children }: PropsWithChildren) {
    return <div className="px-4 pb-6">{children}</div>;
}

const colors = {
    info: 'bg-[#17a2b8] text-white',
    success: 'bg-[#28a745] text-white',
    warning: 'bg-[#ffc107] text-[#1f2d3d]',
    danger: 'bg-[#dc3545] text-white',
    primary: 'bg-[#007bff] text-white',
} as const;

export type Color = keyof typeof colors;

/** Kolorowy kafelek ze statystyką (small-box). */
export function SmallBox({
    value,
    label,
    icon: Icon,
    color,
    href,
}: {
    value: ReactNode;
    label: string;
    icon: LucideIcon;
    color: Color;
    href?: string;
}) {
    return (
        <div className={cn('relative overflow-hidden rounded shadow-sm', colors[color])}>
            <div className="p-4">
                <h3 className="text-3xl font-bold">{value}</h3>
                <p className="text-sm">{label}</p>
            </div>
            <Icon className="absolute right-3 top-3 size-14 opacity-20" />
            {href ? (
                <Link
                    href={href}
                    className="flex items-center justify-center gap-1 bg-black/10 py-1 text-sm hover:bg-black/20"
                >
                    Więcej informacji <ArrowRightCircle className="size-3.5" />
                </Link>
            ) : (
                <div className="h-7 bg-black/10" />
            )}
        </div>
    );
}

/** Karta z nagłówkiem (card). */
export function Card({
    title,
    color,
    children,
    className,
}: PropsWithChildren<{ title?: string; color?: Color; className?: string }>) {
    const outline: Record<Color, string> = {
        info: 'border-t-[#17a2b8]',
        success: 'border-t-[#28a745]',
        warning: 'border-t-[#ffc107]',
        danger: 'border-t-[#dc3545]',
        primary: 'border-t-[#007bff]',
    };

    return (
        <section
            className={cn(
                'rounded bg-white shadow-sm',
                color && cn('border-t-[3px]', outline[color]),
                className,
            )}
        >
            {title && (
                <h3 className="border-b border-[#dee2e6] px-4 py-3 text-lg font-normal">
                    {title}
                </h3>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}
