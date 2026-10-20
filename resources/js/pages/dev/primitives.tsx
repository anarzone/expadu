import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    AnchoredMenu,
    MenuOption,
    MenuOptions,
} from '@/components/ui/anchored-menu';
import { DateControl, berlinToday } from '@/components/ui/date-control';
import { Icon } from '@/components/ui/icon';
import {
    LoadingLines,
    LoadingStatus,
    useDelayedLoading,
} from '@/components/ui/loading';
import { Pill } from '@/components/ui/pill';
import { Sheet } from '@/components/ui/sheet';
import {
    NotListed,
    PLATFORM_NOT_KNOWN,
    StateNotice,
    sourceLabel,
} from '@/components/ui/state-notice';
import { TimeField } from '@/components/ui/time-field';
import { TimeSheet } from '@/components/ui/time-sheet';
import { showToast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';

/**
 * Every shared primitive (components/ui), in use, for checking against the
 * mock and for pages to copy from. Local and staging only.
 */
export default function Primitives() {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [area, setArea] = useState('All areas');
    const [sort, setSort] = useState('Soonest');
    const [date, setDate] = useState(berlinToday());
    const [time, setTime] = useState('13:10');
    const [timesOpen, setTimesOpen] = useState(false);
    const [times, setTimes] = useState({ start: '15:00', end: '16:00' });
    const [loading, setLoading] = useState(false);
    const late = useDelayedLoading(loading);
    const [now] = useState(() => Date.now());

    return (
        <AppLayout>
            <Head title="Primitives" />
            <h1 className="sr-only">Primitives</h1>
            <div className="grid gap-4 md:grid-cols-2">
                <Demo title="Sheet">
                    <button
                        type="button"
                        className="ui-notice-action"
                        onClick={() => setSheetOpen(true)}
                    >
                        <Icon name="plus" variant="line" />
                        Add a plan
                    </button>
                    <Sheet
                        open={sheetOpen}
                        onClose={() => setSheetOpen(false)}
                        title="Add a plan"
                        description="Mon 20 Oct · Today"
                        footer={
                            <button
                                type="button"
                                className="ui-notice-action"
                                onClick={() => setSheetOpen(false)}
                            >
                                <Icon name="plus" variant="line" />
                                Add to this day
                            </button>
                        }
                    >
                        <p className="text-small text-text-soft">
                            A personal plan, not a booking. Close with ×,
                            Escape, a tap outside, or by pulling the top edge
                            down on a phone.
                        </p>
                    </Sheet>
                </Demo>
                <Demo title="Anchored menu">
                    <div className="flex flex-wrap gap-2">
                        <AnchoredMenu
                            label="Search area"
                            sheet="tablet"
                            trigger={(props) => (
                                <button {...props} className="ui-pill">
                                    {area}
                                    <Icon name="down" variant="line" />
                                </button>
                            )}
                        >
                            <MenuOptions columns>
                                {[
                                    'All areas',
                                    'Altstadt-Nord',
                                    'Altstadt-Süd',
                                    'Ehrenfeld',
                                    'Mülheim',
                                    'Nippes',
                                ].map((name) => (
                                    <MenuOption
                                        key={name}
                                        selected={area === name}
                                        onSelect={() => setArea(name)}
                                    >
                                        {name}
                                    </MenuOption>
                                ))}
                            </MenuOptions>
                        </AnchoredMenu>
                        <AnchoredMenu
                            label="Sort"
                            width={200}
                            trigger={(props) => (
                                <button {...props} className="ui-pill">
                                    {sort}
                                    <Icon name="down" variant="line" />
                                </button>
                            )}
                        >
                            <MenuOptions>
                                {['Soonest', 'Closest first', 'Name A–Z'].map(
                                    (name) => (
                                        <MenuOption
                                            key={name}
                                            selected={sort === name}
                                            onSelect={() => setSort(name)}
                                        >
                                            {name}
                                        </MenuOption>
                                    ),
                                )}
                            </MenuOptions>
                        </AnchoredMenu>
                    </div>
                </Demo>
                <Demo title="Toast">
                    <div className="flex flex-wrap gap-4">
                        <button
                            type="button"
                            className="ui-notice-action"
                            onClick={() =>
                                showToast('Lunch with Sara removed', {
                                    undo: () => showToast('Lunch is back'),
                                })
                            }
                        >
                            Remove with Undo
                        </button>
                        <button
                            type="button"
                            className="ui-notice-action"
                            onClick={() => showToast('Saved for later')}
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            className="ui-notice-action"
                            onClick={() =>
                                showToast(
                                    "Couldn't reach the server. Check your connection and try again.",
                                    { error: true },
                                )
                            }
                        >
                            Fail
                        </button>
                    </div>
                </Demo>
                <Demo title="Loading after 140ms">
                    <button
                        type="button"
                        className="ui-notice-action"
                        onClick={() => {
                            setLoading(true);
                            window.setTimeout(() => setLoading(false), 1500);
                        }}
                    >
                        Load for 1.5s
                    </button>
                    {late ? (
                        <LoadingLines lines={2} />
                    ) : (
                        <p className="text-small text-text-soft">
                            Content stays until the placeholder is due.
                        </p>
                    )}
                    <LoadingStatus label="Finding routes" />
                </Demo>
                <Demo title="Date and time">
                    <div className="grid max-w-[310px] gap-3">
                        <DateControl
                            label="Date"
                            value={date}
                            onChange={setDate}
                            min={berlinToday()}
                        />
                        <TimeField
                            label="Travel time"
                            value={time}
                            onChange={setTime}
                        />
                        <button
                            type="button"
                            className="ui-notice-action"
                            onClick={() => setTimesOpen(true)}
                        >
                            <Icon name="clock" variant="line" />
                            Edit time · {times.start}–{times.end}
                        </button>
                        <TimeSheet
                            open={timesOpen}
                            title="Lunch with Sara"
                            note="Pick a new time; it changes as you turn."
                            start={times.start}
                            end={times.end}
                            check={(start) =>
                                start < '07:00'
                                    ? 'The café opens at 07:00.'
                                    : null
                            }
                            onDone={(start, end) => {
                                setTimesOpen(false);
                                setTimes({ start, end });
                            }}
                        />
                    </div>
                </Demo>
                <Demo title="Labels and states">
                    <div className="flex flex-wrap gap-2">
                        <Pill>In your plan</Pill>
                        <Pill tone="attention">12 min delay</Pill>
                        <Pill tone="paper">To review</Pill>
                        <Pill tone="select">Live</Pill>
                        <Pill tone="conflict">Clashes with 14:00</Pill>
                    </div>
                    <p className="text-small text-text-soft">
                        {sourceLabel({ source: 'live' })} ·{' '}
                        {sourceLabel({
                            source: 'recorded',
                            at: new Date(now - 5 * 60_000),
                        })}{' '}
                        · {sourceLabel({ source: 'scheduled' })} · Price:{' '}
                        <NotListed /> · {PLATFORM_NOT_KNOWN}
                    </p>
                    <StateNotice
                        kind="empty"
                        title="No plans added for today"
                        action={{ label: 'Add a plan', icon: 'plus' }}
                    />
                    <StateNotice
                        kind="error"
                        title="Events could not be loaded."
                        onRetry={() => showToast('Trying again')}
                    >
                        Your saved events are still here.
                    </StateNotice>
                    <StateNotice
                        kind="stale"
                        title="Departures"
                        at={new Date(now - 40 * 60_000)}
                    />
                </Demo>
            </div>
        </AppLayout>
    );
}

function Demo({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid content-start gap-3 rounded-xl bg-bg-surface p-5">
            <h2 className="text-section font-semibold">{title}</h2>
            {children}
        </section>
    );
}
