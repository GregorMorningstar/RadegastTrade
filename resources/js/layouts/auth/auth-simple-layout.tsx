import { Link } from '@inertiajs/react';
import PlatformLogo from '@/components/platform-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-slate-100 via-white to-sky-100 p-4 md:p-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            {/* dekoracyjne rozmyte plamy w tle */}
            <div className="pointer-events-none absolute -top-32 -left-32 size-96 rounded-full bg-amber-300/30 blur-3xl dark:bg-amber-500/10" />
            <div className="pointer-events-none absolute -right-32 -bottom-32 size-96 rounded-full bg-sky-400/30 blur-3xl dark:bg-sky-500/10" />

            <div className="relative w-full max-w-md">
                <Link
                    href={home()}
                    className="mx-auto mb-6 flex w-fit flex-col items-center"
                >
                    <PlatformLogo className="size-24 shadow-lg ring-1 ring-black/5" />
                    <span className="sr-only">{title}</span>
                </Link>

                <div className="rounded-3xl border border-white/60 bg-white/80 p-6 shadow-xl shadow-slate-900/5 backdrop-blur-xl sm:p-8 dark:border-white/10 dark:bg-slate-900/70">
                    <div className="mb-6 space-y-1.5 text-center">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>

                    {children}
                </div>

                <div className="mt-6 space-y-1 text-center text-xs text-muted-foreground">
                    <p>
                        <a href="/regulamin" className="hover:text-foreground hover:underline">
                            Regulamin
                        </a>
                        {' · '}
                        <a href="/rodo" className="hover:text-foreground hover:underline">
                            Polityka prywatności (RODO)
                        </a>
                    </p>
                    <p>&copy; {new Date().getFullYear()} RadegastTradeB2BPlatform</p>
                </div>
            </div>
        </div>
    );
}
