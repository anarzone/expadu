import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import './personalisation.css';

/*
 * How we rank (owner, 29 Oct 2026, the consent canvas's board 6; UWG § 5b):
 * the main things that put listings in order on Today, What's on and
 * Around here, as the code does it (App\Home\TodayDiscovery, WhatsOn,
 * AroundHereController), that nobody pays for a place, and where listings
 * come from. Linked from each ranked list.
 */

const TODAY: [string, string][] = [
    [
        'Fits your plan',
        'Nothing that clashes with a plan whose times are known.',
    ],
    [
        'Near you first',
        'Within 3 km of your neighbourhood or Home, then the rest, each soonest first. Tomorrow’s only once nothing is left today.',
    ],
    [
        'What you lean towards, if you turned it on',
        'Claude Haiku, an AI model, puts them in order from what you open and save. It never hides one. Off: everyone sees the same order.',
    ],
    ['What you turned down', 'An event you said isn’t for you stays off.'],
];

export function HowWeRank({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const { userSettings } = usePage<{
        userSettings?: Record<string, unknown>;
    }>().props;
    const on = userSettings?.personalisation === true;

    return (
        <Sheet
            open={open}
            onClose={onClose}
            title="How we rank"
            className="how-we-rank"
        >
            <h3>Today’s ideas</h3>
            <ol className="how-we-rank-list">
                {TODAY.map(([title, line], index) => (
                    <li key={title}>
                        <span className="how-we-rank-num">{index + 1}</span>
                        <span>
                            <strong>{title}</strong>
                            {line}
                        </span>
                    </li>
                ))}
            </ol>
            <h3>What’s on</h3>
            <p>
                Soonest first, unless you pick evening first or closest first.
            </p>
            <h3>Around here</h3>
            <p>Nearest first.</p>
            <p className="how-we-rank-paid">
                <Icon name="cancel" variant="line" />
                Nobody pays to be listed or placed higher.
            </p>
            <p className="how-we-rank-fine">
                Events come from koeln.de, the City of Cologne’s open data,
                KölnTourismus (DatenHub NRW) and a few we add ourselves;
                exhibitions and all-day attractions are left out. Places come
                from OpenStreetMap and Overture Maps.
            </p>
            <p className="how-we-rank-row">
                <span>
                    Personalised suggestions:{' '}
                    <strong>{on ? 'On' : 'Off'}</strong>
                </span>
                <Link href="/settings/privacy">Change</Link>
            </p>
        </Sheet>
    );
}

/** "How we rank" under a ranked list, opening the sheet. */
export function HowWeRankLink({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                className={className ?? 'how-we-rank-link'}
                onClick={() => setOpen(true)}
            >
                How we rank
            </button>
            <HowWeRank open={open} onClose={() => setOpen(false)} />
        </>
    );
}
