import { IconMapPin, IconPhone } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';

export type ServiceData = {
    id: number;
    cat: string;
    name: string;
    type: string;
    emoji: string;
    avatarBg: string;
    rating: number;
    reviews: number;
    verified: boolean;
    languages: string[];
    address: string;
    distance: string;
    phone: string | null;
    acceptingNew: boolean;
    insurance: string[];
    desc: string;
    hours: string;
    website: string;
    tips: { text: string; author: string }[];
};

export function ServiceCard({
    service,
    onClick,
}: {
    service: ServiceData;
    onClick: () => void;
    index?: number;
}) {
    return (
        <article className="mb-3 rounded-2xl border border-border bg-card p-4">
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-cyan-soft text-xl"
                >
                    {service.emoji}
                </span>
                <div className="min-w-0 flex-1">
                    <button
                        type="button"
                        onClick={onClick}
                        className="min-h-11 text-left text-base leading-snug font-semibold hover:text-primary focus-visible:outline-2 focus-visible:outline-cyan"
                    >
                        {service.name}
                    </button>
                    <p className="text-xs text-text-2">{service.type}</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {service.verified && (
                            <span className="rounded-full bg-success-soft px-2 py-1 text-[11px] font-medium text-success">
                                Listing checked
                            </span>
                        )}
                        {service.languages.map((language) => (
                            <span
                                key={language}
                                className="rounded-full bg-secondary px-2 py-1 text-[11px] text-text-2"
                            >
                                {language}
                            </span>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-text-2">
                        {service.acceptingNew
                            ? 'Listed as accepting new clients · confirm directly'
                            : 'Availability to confirm'}
                    </p>
                </div>
                {service.rating > 0 && service.reviews > 0 && (
                    <div className="shrink-0 text-right text-xs text-text-2">
                        <strong className="block text-sm text-foreground">
                            ★ {service.rating}
                        </strong>
                        {service.reviews} reviews
                    </div>
                )}
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                <p className="flex min-w-0 flex-1 items-start gap-1.5 text-xs text-text-2">
                    <IconMapPin
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>
                        {service.address || 'Address to confirm'}
                        {service.distance ? ` · ${service.distance}` : ''}
                    </span>
                </p>
                <div className="flex gap-2">
                    {service.phone && (
                        <a
                            href={`tel:${service.phone}`}
                            className="inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-border bg-card px-3 text-sm font-medium text-foreground"
                        >
                            <IconPhone size={16} stroke={ICON_STROKE} />
                            Call
                        </a>
                    )}
                    <button
                        type="button"
                        onClick={onClick}
                        className="min-h-11 rounded-xl bg-primary px-3 text-sm font-semibold text-white transition-colors hover:bg-primary-hover motion-reduce:transition-none"
                    >
                        Details
                    </button>
                </div>
            </div>
        </article>
    );
}
