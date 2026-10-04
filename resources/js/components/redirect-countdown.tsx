import { Head, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

type Props = {
    title: string;
    description: string;
    icon: LucideIcon;
    redirectTo: string;
    seconds?: number;
};

/**
 * Pełnoekranowa strona z animowanym odliczaniem, która po czasie przekierowuje na wskazany adres.
 */
export default function RedirectCountdown({
    title,
    description,
    icon: Icon,
    redirectTo,
    seconds = 4,
}: Props) {
    const [started, setStarted] = useState(false);
    const [left, setLeft] = useState(seconds);

    useEffect(() => {
        // start animacji po pierwszym renderze, żeby zadziałało przejście CSS
        const start = requestAnimationFrame(() => setStarted(true));
        const tick = setInterval(() => setLeft((s) => Math.max(0, s - 1)), 1000);
        const redirect = setTimeout(
            () => router.visit(redirectTo, { replace: true }),
            seconds * 1000,
        );

        return () => {
            cancelAnimationFrame(start);
            clearInterval(tick);
            clearTimeout(redirect);
        };
    }, [redirectTo, seconds]);

    const radius = 54;
    const circumference = 2 * Math.PI * radius;

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6 text-center">
                <div className="relative size-32 animate-in fade-in zoom-in-50 duration-500">
                    <svg viewBox="0 0 128 128" className="size-full -rotate-90">
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            fill="none"
                            strokeWidth="8"
                            className="stroke-muted"
                        />
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            fill="none"
                            strokeWidth="8"
                            strokeLinecap="round"
                            className="stroke-destructive"
                            style={{
                                strokeDasharray: circumference,
                                strokeDashoffset: started ? circumference : 0,
                                transition: `stroke-dashoffset ${seconds}s linear`,
                            }}
                        />
                    </svg>
                    <Icon className="absolute inset-0 m-auto size-12 animate-pulse text-destructive" />
                </div>

                <div className="animate-in fade-in slide-in-from-bottom-2 duration-700">
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="mt-2 text-muted-foreground">
                        {description} Przekierowanie za {left} s…
                    </p>
                </div>

                <button
                    type="button"
                    onClick={() => router.visit(redirectTo, { replace: true })}
                    className="text-sm underline underline-offset-4 hover:text-primary"
                >
                    Przejdź teraz
                </button>
            </div>
        </>
    );
}
