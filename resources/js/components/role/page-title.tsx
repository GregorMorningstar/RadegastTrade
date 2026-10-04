import { TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export default function PageTitle({
    title,
    icon: Icon = TrendingUp,
}: {
    title: string;
    icon?: LucideIcon;
}) {
    return (
        <div className="flex items-center gap-3 py-3">
            <span className="flex size-10 items-center justify-center rounded-full bg-white shadow-sm dark:bg-slate-900">
                <Icon className="size-4" />
            </span>
            <h1 className="text-2xl font-medium">{title}</h1>
        </div>
    );
}
