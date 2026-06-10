import { Head, Deferred, usePage, Link } from '@inertiajs/react';
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

const severityClasses: Record<Tile['severity'], string> = {
    danger: 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40',
    warn: 'border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40',
    info: 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40',
    neutral: 'border-border bg-card',
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
            className={`flex items-start gap-3 rounded-[14px] border p-4 transition-shadow hover:shadow-sm ${severityClasses[tile.severity]}`}
        >
            <span className="text-2xl leading-none">{tile.emoji}</span>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-semibold text-foreground">
                    {tile.title}
                </div>
                {tile.subtitle && (
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        {tile.subtitle}
                    </div>
                )}
            </div>
        </div>
    );

    return tile.href ? (
        <Link href={tile.href} prefetch className="block">
            {body}
        </Link>
    ) : (
        body
    );
}

export default function Dashboard() {
    const { tiles, weather, auth } = usePage<{
        tiles?: Tile[];
        weather?: Weather;
        auth: { user?: { name?: string } };
    }>().props;

    return (
        <AppLayout>
            <Head title="Today" />
            <ServiceErrorBanner />
            <div className="mx-auto w-full max-w-[680px] px-4 pt-6 pb-16 md:px-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="font-display text-[22px] font-medium tracking-tight">
                        {getGreeting(auth?.user?.name)}
                    </h1>
                    <Deferred data="weather" fallback={null}>
                        {weather ? (
                            <span className="text-sm text-muted-foreground">
                                {weather.emoji}{' '}
                                {Math.round(weather.temperature)}°C
                            </span>
                        ) : null}
                    </Deferred>
                </div>

                {/* Day Composer prompt lands here (phase 3) */}

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
