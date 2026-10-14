import { Link } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconClock,
    IconCloud,
    IconDots,
    IconFileText,
    IconRoute,
    IconTrain,
} from '@tabler/icons-react';
import type { RailCard, Tile } from '@/components/today/model';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ICON_STROKE } from '@/constants/icons';
import { alerts, bureaucracy, composer, events, timetable } from '@/routes';

export type TileAction = 'done' | 'snooze' | 'dismiss';
export type Weather = {
    available?: boolean;
    temperature: number;
    condition: string;
} | null;

function actionFor(tile: Tile) {
    if (tile.type === 'bureaucracy_deadline') {
        return {
            href: tile.href ?? bureaucracy.url(),
            label: 'Review paperwork',
        };
    }

    if (tile.type === 'tonight_events') {
        return { href: tile.href ?? events.url(), label: 'Open event' };
    }

    if (tile.type === 'weather_alert') {
        return { href: composer.url(), label: 'Plan around this' };
    }

    return { href: tile.href ?? alerts.url(), label: 'See details' };
}

export function SignalRow({
    tile,
    onAction,
    busy,
}: {
    tile: Tile;
    onAction: (action: TileAction) => void;
    busy: boolean;
}) {
    const action = actionFor(tile);
    const paperwork = tile.type === 'bureaucracy_deadline';

    return (
        <article className={`today-signal today-signal-${tile.severity}`}>
            <span className="today-signal-icon">
                {paperwork ? (
                    <IconFileText size={21} stroke={ICON_STROKE} />
                ) : (
                    <IconAlertTriangle size={21} stroke={ICON_STROKE} />
                )}
            </span>
            <div className="min-w-0 flex-1">
                <h3>{tile.title}</h3>
                {tile.subtitle && <p>{tile.subtitle}</p>}
                <Link href={action.href} className="today-text-link">
                    {action.label}
                </Link>
            </div>
            {!paperwork && tile.key && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label={`Actions for ${tile.title}`}
                            className="today-icon-button"
                            disabled={busy}
                        >
                            <IconDots size={20} />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => onAction('done')}>
                            Done for today
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onAction('snooze')}>
                            Snooze for a few hours
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onAction('dismiss')}>
                            Dismiss
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </article>
    );
}

export function PaperworkContinuation({
    card,
    loading,
}: {
    card?: RailCard;
    loading: boolean;
}) {
    return (
        <section
            className="today-paperwork"
            aria-labelledby="today-paperwork-heading"
        >
            <h2 id="today-paperwork-heading">Paperwork</h2>
            {loading ? (
                <p role="status" className="today-muted">
                    Loading your next step…
                </p>
            ) : (
                <Link
                    href={card?.href ?? bureaucracy()}
                    className="today-paperwork-link"
                >
                    <span className="today-paperwork-icon">
                        <IconFileText size={23} stroke={ICON_STROKE} />
                    </span>
                    <span>
                        <strong>
                            {card?.name ?? 'Guidance when you need it'}
                        </strong>
                        <small>
                            {card?.due_label ??
                                'Explore reviewed information for your situation.'}
                        </small>
                        {card?.verified && (
                            <small>Verified {card.verified}</small>
                        )}
                    </span>
                </Link>
            )}
        </section>
    );
}

export function CityHelp({
    weather,
    rainSummary,
}: {
    weather?: Weather;
    rainSummary?: string;
}) {
    const hasWeather =
        weather?.available !== false &&
        weather &&
        Number.isFinite(weather.temperature);

    return (
        <section
            className="today-city-help"
            aria-labelledby="today-city-heading"
        >
            <div className="today-section-heading">
                <h2 id="today-city-heading">City help</h2>
                {hasWeather && (
                    <span className="today-weather" title={weather.condition}>
                        <IconCloud size={20} stroke={ICON_STROKE} />
                        {Math.round(weather.temperature)}°
                    </span>
                )}
            </div>
            {hasWeather && (
                <p className="today-muted today-weather-summary">
                    {[weather.condition, rainSummary]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}
            <div className="today-help-links">
                <Link href={timetable({ query: { view: 'plan' } })}>
                    <IconRoute size={20} stroke={ICON_STROKE} />
                    <span>Plan a trip</span>
                </Link>
                <Link href={timetable({ query: { view: 'timetable' } })}>
                    <IconTrain size={20} stroke={ICON_STROKE} />
                    <span>Check departures</span>
                </Link>
            </div>
        </section>
    );
}

export function AmbientSignals({ tiles }: { tiles: Tile[] }) {
    if (!tiles.length) {
        return null;
    }

    return (
        <section className="today-ambient" aria-label="Good to know">
            {tiles.map((tile) => (
                <Link key={tile.key || tile.type} href={actionFor(tile).href}>
                    <IconClock size={17} stroke={ICON_STROKE} />
                    <p>
                        <strong>{tile.title}</strong>
                        {tile.subtitle && <span>{tile.subtitle}</span>}
                    </p>
                </Link>
            ))}
        </section>
    );
}
