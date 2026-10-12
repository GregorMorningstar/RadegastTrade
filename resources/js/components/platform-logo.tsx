import { cn } from '@/lib/utils';

/** Logo platformy (public/images/logo.png). Ma białe tło, więc w ciemnym motywie leży na jasnej plakietce. */
export default function PlatformLogo({ className }: { className?: string }) {
    return (
        <img
            src="/images/logo.png"
            alt="RadegastTradeB2BPlatform"
            className={cn('rounded-xl bg-white object-contain', className)}
        />
    );
}
