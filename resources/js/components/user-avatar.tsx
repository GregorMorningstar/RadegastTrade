import { cn } from '@/lib/utils';

export const DEFAULT_AVATAR = '/images/default-avatar.svg';

/** Zdjęcie profilowe; bez zdjęcia (lub gdy się nie wczyta) domyślny awatar. Opcjonalna zielona kropka aktywności. */
export default function UserAvatar({
    src,
    name,
    size = 32,
    active,
    className,
}: {
    src?: string | null;
    name: string;
    size?: number;
    active?: boolean;
    className?: string;
}) {
    return (
        <span
            className={cn('relative inline-block shrink-0', className)}
            style={{ width: size, height: size }}
        >
            <img
                src={src || DEFAULT_AVATAR}
                alt={name}
                width={size}
                height={size}
                className="size-full rounded-full bg-[#c9c9c9] object-cover"
                onError={(event) => {
                    if (!event.currentTarget.src.endsWith(DEFAULT_AVATAR)) {
                        event.currentTarget.src = DEFAULT_AVATAR;
                    }
                }}
            />
            {active !== undefined && (
                <span
                    title={active ? 'Aktywny' : 'Nieaktywny'}
                    className={cn(
                        'absolute bottom-0 right-0 size-3 rounded-full border-2 border-white',
                        active ? 'bg-[#28a745]' : 'bg-[#adb5bd]',
                    )}
                />
            )}
        </span>
    );
}
