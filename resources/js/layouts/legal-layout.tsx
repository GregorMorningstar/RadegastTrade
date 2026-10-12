import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import PlatformLogo from '@/components/platform-logo';
import { home } from '@/routes';

/** Układ stron z dokumentami prawnymi (regulamin, RODO): dostępne bez logowania. */
export default function LegalLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-svh bg-gradient-to-br from-slate-100 via-white to-sky-100 px-4 py-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            <div className="mx-auto w-full max-w-3xl">
                <Link href={home()} className="mb-6 flex w-fit items-center gap-3">
                    <PlatformLogo className="size-14 shadow ring-1 ring-black/5" />
                    <span className="text-lg font-semibold">RadegastTradeB2BPlatform</span>
                </Link>

                <article className="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-xl shadow-slate-900/5 sm:p-10 dark:border-white/10 dark:bg-slate-900/80">
                    {children}
                </article>

                <nav className="mt-6 flex justify-center gap-6 text-sm text-muted-foreground">
                    <Link href="/regulamin" className="hover:text-foreground hover:underline">
                        Regulamin
                    </Link>
                    <Link href="/rodo" className="hover:text-foreground hover:underline">
                        Polityka prywatności (RODO)
                    </Link>
                </nav>
            </div>
        </div>
    );
}
