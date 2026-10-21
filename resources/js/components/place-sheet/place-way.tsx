/* Get there, in the place sheet (the mock's connected/place-sheet.js
   directions panel): from where you are around (Around here's point), or
   what you type, to the place, leaving now; by tram and bus, on foot or by
   bike. The ways are Travel's own (owner, 18 Oct 2026: trips look like
   Travel everywhere), as on an event's Get there: the mock's panel only
   previews them. */

import { useEffect, useState } from 'react';
import { useFrom } from '@/components/get-there/use-from';
import { WayResults } from '@/components/get-there/way-results';
import { useWays } from '@/components/get-there/ways';
import type { WayMode, WayPlace } from '@/components/get-there/ways';
import type { PlanAnswer } from '@/components/travel/plan/plan-model';
import { Icon } from '@/components/ui/icon';
import type { DayProjection } from '@/types/day';
import './place-way.css';

const MODES: [WayMode, string][] = [
    ['transit', 'Transit'],
    ['walk', 'Walk'],
    ['bike', 'Cycle'],
];

export function PlaceWay({
    place,
    to,
    from: start,
}: {
    /** The place's id. */
    place: number;
    to: WayPlace;
    from: WayPlace | null;
}) {
    const from = useFrom(start);
    const [mode, setMode] = useState<WayMode>('transit');
    const [picked, setPicked] = useState<[PlanAnswer | null, string]>([
        null,
        '',
    ]);
    const [opened, setOpened] = useState<[PlanAnswer | null, Set<string>]>([
        null,
        new Set(),
    ]);
    // Today, for Travel's "My day" check on the ways.
    const [day, setDay] = useState<DayProjection | null | undefined>(undefined);

    useEffect(() => {
        const controller = new AbortController();
        fetch('/api/day', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then(setDay)
            .catch(() => {});

        return () => controller.abort();
    }, []);

    const { status, answer, ways, retry } = useWays({
        from: from.place,
        to,
        tag: { item: `spot:${place}`, surface: 'place_sheet' },
    });
    const shown = ways[mode];
    const pickedId = picked[0] === answer ? picked[1] : '';
    const expanded = opened[0] === answer ? opened[1] : new Set<string>();
    const chosen = shown.find((x) => x.id === pickedId) ?? shown[0] ?? null;

    return (
        <form
            className="place-journey"
            onSubmit={(event) => {
                event.preventDefault();
                from.commit();
            }}
        >
            <div className="place-route">
                <label className="place-route-origin">
                    <span className="route-dot" aria-hidden="true" />
                    <span className="route-field">
                        From
                        <input
                            name="from"
                            placeholder="Address, place or stop"
                            maxLength={120}
                            required
                            autoComplete="off"
                            value={from.text}
                            onChange={(e) => from.setText(e.target.value)}
                            onBlur={() => from.commit()}
                        />
                    </span>
                </label>
                <div className="place-journey-destination">
                    <Icon name="pin" variant="line" />
                    <span>
                        <small>To</small>
                        <strong>{to.name}</strong>
                    </span>
                </div>
            </div>
            <fieldset>
                <legend>How would you like to go?</legend>
                {MODES.map(([value, name]) => (
                    <label key={value}>
                        <input
                            type="radio"
                            name="mode"
                            value={value}
                            checked={mode === value}
                            onChange={() => setMode(value)}
                        />
                        <span>{name}</span>
                    </label>
                ))}
            </fieldset>
            <div className="gt">
                <WayResults
                    status={status}
                    place={from.place}
                    text={from.text}
                    finding={from.finding}
                    mode={mode}
                    shown={shown}
                    chosen={chosen}
                    target={null}
                    day={day}
                    destination={to.name}
                    onPick={(id) => setPicked([answer, id])}
                    expanded={expanded}
                    onToggle={(id) => {
                        setPicked([answer, id]);
                        const next = new Set(expanded);

                        if (next.has(id)) {
                            next.delete(id);
                        } else {
                            next.add(id);
                        }

                        setOpened([answer, next]);
                    }}
                    onRetry={retry}
                    onWalk={() => setMode('walk')}
                />
            </div>
        </form>
    );
}
