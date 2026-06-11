import { Head, Deferred, usePage, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ServiceErrorBanner } from '@/components/service-error-banner';
import AppLayout from '@/layouts/app-layout';

type Tile = {
    type: string;
    title: string;
    subtitle: string;
    emoji: string;
    severity: 'danger' | 'warn' | 'info' | 'neutral';
    score: number;
    href: string | null;
    meta: Record<string, unknown>;
};

type Weather = {
    temperature: number;
    emoji: string;
    condition: string;
} | null;

const EXAMPLE_CHIPS = [
    '🌳 Free Saturday afternoon',
    '👶 Something with kids tomorrow',
    '🍻 Meet people tonight',
];

const tileClasses: Record<Tile['severity'], string> = {
    danger: 'border-danger-soft border-l-danger bg-danger-soft',
    warn: 'border-warn-soft border-l-warn bg-warn-soft',
    info: 'border-accent-soft border-l-primary bg-accent-soft',
    neutral: 'border-border border-l-border bg-card',
};

const tileTitleClasses: Record<Tile['severity'], string> = {
    danger: 'text-danger dark:text-[#F08A80]',
    warn: 'text-foreground',
    info: 'text-primary dark:text-[#8FAAF0]',
    neutral: 'text-foreground',
};

function getGreeting(name?: string): string {
    const hour = new Date().getHours();
    const part =
        hour < 12
            ? 'Good morning'
            : hour < 18
              ? 'Good afternoon'
              : 'Good evening';

    return name ? `${part}, ${name.split(' ')[0]}` : part;
}

function TileCard({ tile }: { tile: Tile }) {
    const body = (
        <div
            className={`flex w-full items-center gap-3.5 rounded-[14px] border border-l-[3px] p-4 text-left transition-all hover:-translate-y-px hover:shadow-sm ${tileClasses[tile.severity]}`}
        >
            <span className="w-7 shrink-0 text-center text-[22px] leading-none">
                {tile.emoji}
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`block text-sm leading-snug font-semibold ${tileTitleClasses[tile.severity]}`}
                >
                    {tile.title}
                </span>
                {tile.subtitle && (
                    <span className="mt-0.5 block text-[13px] leading-relaxed text-muted-foreground">
                        {tile.subtitle}
                    </span>
                )}
            </span>
            <span className="shrink-0 text-base text-muted-foreground/60 transition-transform group-hover:translate-x-0.5">
                ›
            </span>
        </div>
    );

    return tile.href ? (
        <Link href={tile.href} prefetch className="group block">
            {body}
        </Link>
    ) : (
        <div className="group">{body}</div>
    );
}

export default function Dashboard() {
    const { tiles, weather, auth } = usePage<{
        tiles?: Tile[];
        weather?: Weather;
        auth: { user?: { name?: string } };
    }>().props;

    const [prompt, setPrompt] = useState('');

    function openComposer(text: string) {
        const trimmed = text.trim();
        router.visit(
            trimmed
                ? `/composer?prompt=${encodeURIComponent(trimmed)}`
                : '/composer',
        );
    }

    return (
        <AppLayout>
            <Head title="Today" />
            <ServiceErrorBanner />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-24 md:px-6">
                {/* Date line */}
                <div className="mb-1 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                    {new Date().toLocaleDateString('en-GB', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}
                    {' · Cologne'}
                </div>

                {/* Greeting + weather chip */}
                <div className="mb-6 flex items-start justify-between gap-3">
                    <h1 className="font-display text-[26px] leading-tight font-medium tracking-tight">
                        {getGreeting(auth?.user?.name)}
                    </h1>
                    <Deferred data="weather" fallback={null}>
                        {weather ? (
                            <span className="mt-1 shrink-0 rounded-full border border-border bg-card px-3 py-1.5 text-[13px] font-medium">
                                {weather.emoji}{' '}
                                {Math.round(weather.temperature)}°C
                            </span>
                        ) : null}
                    </Deferred>
                </div>

                {/* Day Composer prompt box */}
                <div className="mb-3 rounded-[20px] border border-border bg-card p-[18px] shadow-sm transition-colors focus-within:border-primary">
                    <div className="mb-2.5 flex items-center gap-1.5 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                        ✨ Day Composer
                    </div>
                    <div className="flex items-center gap-2.5">
                        <input
                            type="text"
                            value={prompt}
                            onChange={(e) => setPrompt(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    openComposer(prompt);
                                }
                            }}
                            placeholder="What do you want to do? Try: free Saturday afternoon…"
                            className="min-w-0 flex-1 border-none bg-transparent text-base outline-none placeholder:text-muted-foreground/60"
                        />
                        <button
                            onClick={() => openComposer(prompt)}
                            title="Compose"
                            className="flex size-[38px] shrink-0 cursor-pointer items-center justify-center rounded-full bg-primary text-base text-white transition-colors hover:bg-accent-hover"
                        >
                            →
                        </button>
                    </div>
                </div>

                {/* Example chips */}
                <div className="mb-7 flex flex-wrap gap-2">
                    {EXAMPLE_CHIPS.map((chip) => (
                        <button
                            key={chip}
                            onClick={() =>
                                openComposer(chip.replace(/^\S+\s/, ''))
                            }
                            className="cursor-pointer rounded-full border border-border bg-card px-3.5 py-2 text-[13px] font-medium text-muted-foreground transition-all hover:border-primary hover:bg-accent-soft hover:text-primary"
                        >
                            {chip}
                        </button>
                    ))}
                </div>

                {/* Urgency tiles */}
                <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                    Needs your attention
                </div>
                <Deferred
                    data="tiles"
                    fallback={
                        <div className="flex flex-col gap-2.5">
                            {[1, 2, 3].map((i) => (
                                <div
                                    key={i}
                                    className="h-[76px] animate-pulse rounded-[14px] bg-secondary"
                                />
                            ))}
                        </div>
                    }
                >
                    <div className="flex flex-col gap-2.5">
                        {(tiles ?? []).map((tile, i) => (
                            <TileCard key={`${tile.type}-${i}`} tile={tile} />
                        ))}
                        {(tiles ?? []).length === 0 && (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Nothing urgent right now. Enjoy your day.
                            </div>
                        )}
                    </div>
                </Deferred>
            </div>
        </AppLayout>
    );
}
