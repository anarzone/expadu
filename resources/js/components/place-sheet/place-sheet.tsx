/* One place, one sheet, one scroll (the mock's connected/place-sheet.js,
   Around here direction A; owner 23 Oct 2026). No tabs: a band with a
   rights-checked photo or the kind's colour and "No photo yet", the name
   and kind line, status chips, only the rows the listing has, "In this
   park" for a park's contents, one line naming what isn't listed, Getting
   there into Travel, and Save / Share / Plan a visit, where Plan a visit
   opens under the place with Slide. It fits what it holds. Around here
   opens it from a row (beside its map on a laptop); Composer opens it by a
   place's id. */

import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { PlaceIcon } from '@/components/around-here/place-icon';
import {
    cuisineNames,
    distanceText,
} from '@/components/around-here/place-model';
import { api } from '@/components/composer/types';
import { Sheet } from '@/components/ui/sheet';
import { showToast } from '@/components/ui/toast';
import { shareItem, useCanShare } from '@/lib/share';
import { useDwell } from '@/lib/signals';
import { cn } from '@/lib/utils';
import { timetable } from '@/routes';
import { PlanVisitFooter, PlanVisitPanel, usePlanVisit } from './plan-visit';
import '@/components/around-here/place-row.css';
import './place-sheet.css';

export type PlaceSheetProps = {
    /** The place shown, by its id; null closes the sheet. */
    place: number | null;
    /** The time its listed hours are read at, HH:MM today (now by default). */
    at?: string;
    /** Where you'd come from (Around here's point): its distance is from there. */
    from?: { name: string; lat: number; lng: number } | null;
    /** Opens with Plan a visit already open. */
    initial?: 'about' | 'plan';
    /** On a laptop, over the list beside the map rather than in the middle. */
    beside?: boolean;
    onClose: () => void;
    /** Saved or not, as the person changes it here, so a list can follow. */
    onSaved?: (place: number, saved: boolean) => void;
};

type Fact = { value: string | null; status: string };

type Detail = {
    id: number;
    name: string;
    fine_label: string | null;
    veedel: string | null;
    lat: number | null;
    lng: number | null;
    kind_icon: string;
    feedback_state: string | null;
    /** Whether it can be recommended and planned to (active, open to all). */
    recommendation_status?: 'available' | 'unavailable';
    hours_at?:
        | { state: 'open'; until: string }
        | { state: 'closed'; opens?: string; today?: boolean }
        | { state: 'unknown' };
    /** A rights-checked photo and its credit; null for most places. */
    photo_url: string | null;
    photo_attribution: string | null;
    photo_source_url: string | null;
    photo_license_url: string | null;
    /** A park's own facilities with counts ("Tennis court", 2). */
    facilities?: { kind: string; count: number }[];
    place_facts: {
        hours: {
            status: string;
            parsed?: Record<string, [string, string][]> | null;
            source_url: string | null;
            observed_at: string | null;
        };
        contact: { website: Fact; phone: Fact; address: Fact };
        access: { value: string | null; status: string };
        fee: { value: string | null; status: string };
        practical: Record<string, Fact>;
    };
};

const EATING = ['cafe', 'restaurant', 'fastfood', 'bakery'];
/** Open spaces have no hours, address or website to miss. */
const OPEN_SPACES = ['park', 'nature', 'playground'];
const DAYS = [
    ['mon', 'Mon'],
    ['tue', 'Tue'],
    ['wed', 'Wed'],
    ['thu', 'Thu'],
    ['fri', 'Fri'],
    ['sat', 'Sat'],
    ['sun', 'Sun'],
] as const;
const DIETS = ['vegan', 'vegetarian', 'halal', 'kosher', 'gluten_free'];

const known = (fact: Fact | undefined) =>
    fact && fact.status === 'known' && fact.value ? fact.value : null;
const capital = (text: string) => text.replace(/^./, (c) => c.toUpperCase());

function todayText(hours: Detail['hours_at']): string | null {
    if (!hours || hours.state === 'unknown') {
        return null;
    }

    if (hours.state === 'open') {
        return `Open until ${['24:00', '00:00'].includes(hours.until) ? 'midnight' : hours.until}`;
    }

    if (hours.opens) {
        return `Opens at ${hours.opens}`;
    }

    return hours.today ? 'Closed for the day' : 'Closed today';
}

function websiteOf(
    value: string | null,
): { href: string; text: string } | null {
    try {
        const url = value ? new URL(value) : null;

        return url && ['http:', 'https:'].includes(url.protocol)
            ? { href: url.href, text: url.hostname.replace(/^www\./, '') }
            : null;
    } catch {
        return null;
    }
}

/**
 * The week as few lines as it takes: "Every day, all day", or days with the
 * same hours together ("Mon – Fri", "Sat, Sun"), today marked.
 */
function weekLines(
    week: Record<string, [string, string][]>,
    today: string,
): { days: string; text: string; today: boolean }[] {
    const slots = (key: string) => week[key] ?? [];
    const allDay = DAYS.every(
        ([key]) =>
            slots(key).length === 1 &&
            slots(key)[0][0] === '00:00' &&
            ['24:00', '00:00'].includes(slots(key)[0][1]),
    );

    if (allDay) {
        return [{ days: 'Every day', text: 'All day', today: true }];
    }

    const groups: { text: string; keys: string[] }[] = [];
    DAYS.forEach(([key]) => {
        const text = slots(key).length
            ? slots(key)
                  .map(([a, b]) => `${a} – ${b}`)
                  .join(', ')
            : 'Closed';
        const last = groups.at(-1);

        if (last && last.text === text) {
            last.keys.push(key);
        } else {
            groups.push({ text, keys: [key] });
        }
    });
    const short = Object.fromEntries(DAYS) as Record<string, string>;

    return groups.map(({ text, keys }) => ({
        days:
            keys.length > 2
                ? `${short[keys[0]]} – ${short[keys.at(-1)!]}`
                : keys.map((key) => short[key]).join(', '),
        text,
        today: keys.includes(today),
    }));
}

/** "Hours, wheelchair access and phone". */
const listWords = (words: string[]) =>
    words.length > 1
        ? `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`
        : (words[0] ?? '');

/** A park's facility's icon, by what it is. */
const facilityIcon = (kind: string) => {
    const k = kind.toLowerCase();

    return k.includes('table tennis')
        ? 'table_tennis'
        : k.includes('boules')
          ? 'boules'
          : k.includes('playground')
            ? 'playground'
            : k.includes('dog')
              ? 'dog_park'
              : /pitch|court|skate|basketball|tennis/.test(k)
                ? 'pitch'
                : 'park';
};

export function PlaceSheet({
    place,
    at,
    from = null,
    initial = 'about',
    beside = false,
    onClose,
    onSaved,
}: PlaceSheetProps) {
    const [detail, setDetail] = useState<Detail | null>(null);
    // The place whose details couldn't load, if it's the one asked for.
    const [failedFor, setFailedFor] = useState<number | null>(null);
    const [saved, setSaved] = useState(false);
    useDwell('place_sheet', 'spot', place);

    useEffect(() => {
        if (place === null) {
            return;
        }

        const controller = new AbortController();
        fetch(
            `/api/places/${place}${at ? `?at=${encodeURIComponent(at)}` : ''}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((json: { data: Detail }) => {
                setDetail(json.data);
                setSaved(json.data.feedback_state === 'saved');
            })
            .catch(
                (error: Error) =>
                    error.name !== 'AbortError' && setFailedFor(place),
            );

        return () => controller.abort();
    }, [place, at]);

    const d = detail && detail.id === place ? detail : null;
    const failed = failedFor === place && !d;

    const save = () => {
        if (!d) {
            return;
        }

        const next = !saved;
        setSaved(next);
        api(`/api/places/${d.id}/feedback`, {
            action: next ? 'saved' : 'clear',
        })
            .then(() => onSaved?.(d.id, next))
            .catch(() => {
                setSaved(!next);
                showToast('That place wasn’t saved. Please try again.', {
                    error: true,
                });
            });
    };

    return (
        <Sheet
            open={place !== null}
            onClose={onClose}
            className={cn('place-sheet', beside && 'is-beside')}
            title={d?.name ?? 'Place'}
        >
            {failed ? (
                <p className="place-sheet-status">
                    This place couldn’t open. Please try again.
                </p>
            ) : d ? (
                <PlaceDetail
                    key={d.id}
                    detail={d}
                    from={from}
                    initial={initial}
                    saved={saved}
                    onSave={save}
                />
            ) : (
                <p className="place-sheet-status" role="status">
                    <PlaceIcon name="pin" /> Loading the place
                </p>
            )}
        </Sheet>
    );
}

/** The band: a rights-checked photo, or the kind's colour and "No photo yet". */
function Band({ detail: d }: { detail: Detail }) {
    const [broken, setBroken] = useState(false);

    if (d.photo_url && !broken) {
        return (
            <div className="place-sheet-band has-photo">
                <img src={d.photo_url} alt="" onError={() => setBroken(true)} />
            </div>
        );
    }

    return (
        <div className="place-sheet-band" data-kind={d.kind_icon}>
            <span className="place-sheet-band-art" aria-hidden="true">
                <PlaceIcon name={d.kind_icon} />
                <PlaceIcon name={d.kind_icon} />
                <PlaceIcon name={d.kind_icon} />
            </span>
            <span className="place-sheet-band-note">No photo yet</span>
        </div>
    );
}

function PlaceDetail({
    detail: d,
    from,
    initial,
    saved,
    onSave,
}: {
    detail: Detail;
    from: { name: string; lat: number; lng: number } | null;
    initial: 'about' | 'plan';
    saved: boolean;
    onSave: () => void;
}) {
    const [planning, setPlanning] = useState(initial === 'plan');
    const plan = useRef<HTMLElement>(null);

    // Opened, Plan a visit comes into view once it has slid open.
    useEffect(() => {
        if (!planning) {
            return;
        }

        const timer = window.setTimeout(
            () =>
                plan.current?.scrollIntoView({
                    block: 'nearest',
                    behavior: window.matchMedia(
                        '(prefers-reduced-motion: reduce)',
                    ).matches
                        ? 'auto'
                        : 'smooth',
                }),
            200,
        );

        return () => window.clearTimeout(timer);
    }, [planning]);
    const visit = usePlanVisit(d.id, d.name, planning);
    const canShare = useCanShare();
    const facts = d.place_facts;
    const practical = facts.practical;
    const kind = d.kind_icon;
    const area = kind === 'nature';
    const openSpace = OPEN_SPACES.includes(kind);
    const eats = EATING.includes(kind);
    const point = d.lat !== null && d.lng !== null;
    // From the point you're around; nothing when that's the place itself.
    const km =
        from && point ? kmBetween(from, { lat: d.lat!, lng: d.lng! }) : null;
    const away =
        km !== null && km >= 0.025 ? distanceText({ km, area: false }) : '';
    const sport = known(practical.sport);
    const kindLine = [
        d.fine_label,
        sport
            ? capital(
                  sport
                      .split(';')
                      .map((s) => s.trim().replace(/_/g, ' '))
                      .join(', '),
              )
            : cuisineNames(known(practical.cuisine)),
        d.veedel,
        away,
    ]
        .filter(Boolean)
        .join(' · ');

    // Status chips: today's hours, then only what the listing records.
    const week = facts.hours.status === 'known' ? facts.hours.parsed : null;
    const allWeek =
        !!week &&
        weekLines(week, '')[0]?.days === 'Every day' &&
        weekLines(week, '')[0]?.text === 'All day';
    const hoursNow = area
        ? null
        : allWeek
          ? 'Open all day'
          : todayText(d.hours_at);
    const chips: { text: string; open?: boolean }[] = [];

    if (!area && !(openSpace && !hoursNow)) {
        chips.push(
            hoursNow
                ? {
                      text: hoursNow,
                      open: allWeek || d.hours_at?.state === 'open',
                  }
                : { text: 'No hours listed' },
        );
    }

    const wheelchair = known(practical.wheelchair);

    if (wheelchair) {
        chips.push({
            text:
                {
                    yes: 'Step-free',
                    designated: 'Step-free',
                    limited: 'Partly step-free',
                    no: 'Not step-free',
                }[wheelchair] ?? capital(wheelchair),
        });
    }

    if (eats) {
        DIETS.forEach((diet) => {
            const value = known(practical[`diet:${diet}`]);
            const word = capital(diet.replace('_', '-'));

            if (value === 'only') {
                chips.push({ text: `${word} only` });
            } else if (value === 'yes') {
                chips.push({ text: `${word} options` });
            }
        });

        const outdoor = known(practical.outdoor_seating);

        if (outdoor) {
            chips.push({
                text:
                    outdoor === 'no' ? 'No outdoor seating' : 'Outdoor seating',
            });
        }
    }

    if (
        ['wlan', 'yes', 'wifi'].includes(
            (known(practical.internet_access) ?? '').toLowerCase(),
        )
    ) {
        chips.push({ text: 'Wi-Fi' });
    }

    const fee = facts.fee.status === 'known' ? facts.fee.value : null;

    if (fee) {
        chips.push({
            text:
                {
                    no: 'Free entry',
                    free: 'Free entry',
                    yes: 'Paid entry',
                    paid: 'Paid entry',
                }[fee] ?? capital(fee),
        });
    }

    const access = facts.access.status === 'known' ? facts.access.value : null;

    if (access && !['yes', 'public'].includes(access)) {
        chips.push({
            text:
                { customers: 'Customers only', private: 'Private' }[access] ??
                capital(access),
        });
    }

    const surface = known(practical.surface);

    if (surface) {
        chips.push({ text: `${capital(surface.replace(/_/g, ' '))} paths` });
    }

    // Only the rows the listing has.
    const address = area ? null : known(facts.contact.address);
    const website = websiteOf(known(facts.contact.website));
    const phone = known(facts.contact.phone);
    const today = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][
        new Date().getDay()
    ];
    const missing = openSpace
        ? []
        : [
              week ? '' : 'hours',
              address || area ? '' : 'address',
              wheelchair ? '' : 'wheelchair access',
              website ? '' : 'website',
              phone ? '' : 'phone',
          ].filter(Boolean);
    const facilities = d.facilities ?? [];
    const observed = facts.hours.observed_at
        ? new Date(facts.hours.observed_at).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              timeZone: 'Europe/Berlin',
          })
        : null;

    const share = async () => {
        // The place has no page of its own: its map point.
        const url = point
            ? `https://www.openstreetmap.org/?mlat=${d.lat}&mlon=${d.lng}#map=17/${d.lat}/${d.lng}`
            : location.href;

        if (canShare) {
            shareItem(
                {
                    title: d.name,
                    text: [d.name, address].filter(Boolean).join(', '),
                    url,
                },
                { surface: 'place_sheet', item_type: 'spot', item_id: d.id },
            );

            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            showToast('Link copied');
        } catch {
            showToast(url);
        }
    };

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            showToast('Address copied');
        } catch {
            showToast(text);
        }
    };

    return (
        <>
            <div className="place-sheet-scroll">
                <Band detail={d} />
                <div className="place-sheet-body">
                    <div className="place-sheet-name" aria-hidden="true">
                        <strong>{d.name}</strong>
                        <span>{kindLine}</span>
                    </div>
                    {chips.length ? (
                        <div className="place-chips">
                            {chips.map((chip) => (
                                <span
                                    key={chip.text}
                                    className={cn(
                                        'place-chip',
                                        chip.open ? 'is-open' : 'is-quiet',
                                    )}
                                >
                                    {chip.open ? (
                                        <i aria-hidden="true" />
                                    ) : null}
                                    {chip.text}
                                </span>
                            ))}
                        </div>
                    ) : null}
                    {address || week || website || phone ? (
                        <dl className="place-known">
                            {address ? (
                                <div>
                                    <dt>Address</dt>
                                    <dd>
                                        <span>
                                            {address.replace(
                                                /,\s*(\d{5}\s+)?Köln$/,
                                                '',
                                            )}
                                        </span>
                                        <button
                                            type="button"
                                            className="place-copy"
                                            onClick={() => void copy(address)}
                                        >
                                            Copy
                                        </button>
                                    </dd>
                                </div>
                            ) : null}
                            {week ? (
                                <div>
                                    <dt>Hours</dt>
                                    <dd>
                                        <span className="place-week-lines">
                                            {weekLines(week, today).map(
                                                (line) => (
                                                    <span
                                                        key={line.days}
                                                        className={cn(
                                                            line.today &&
                                                                'is-today',
                                                        )}
                                                    >
                                                        <b>{line.days}</b>
                                                        {line.text}
                                                    </span>
                                                ),
                                            )}
                                        </span>
                                    </dd>
                                </div>
                            ) : null}
                            {website ? (
                                <div>
                                    <dt>Website</dt>
                                    <dd>
                                        <a
                                            href={website.href}
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            {website.text} ↗
                                        </a>
                                    </dd>
                                </div>
                            ) : null}
                            {phone ? (
                                <div>
                                    <dt>Phone</dt>
                                    <dd>
                                        <a
                                            href={`tel:${phone.replace(/\s/g, '')}`}
                                        >
                                            {phone}
                                        </a>
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                    ) : null}
                    {facilities.length ? (
                        <section
                            className="place-contents"
                            aria-labelledby="place-contents-title"
                        >
                            <h3 id="place-contents-title">
                                In this {(d.fine_label ?? 'park').toLowerCase()}
                            </h3>
                            <ul>
                                {facilities.map((f) => (
                                    <li key={f.kind}>
                                        <span className="place-contents-icon">
                                            <PlaceIcon
                                                name={facilityIcon(f.kind)}
                                            />
                                        </span>
                                        <strong>{f.kind}</strong>
                                        {f.count > 1 ? (
                                            <em>{f.count}</em>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                    {missing.length ? (
                        <p className="place-missing">
                            Not listed for {d.name} yet: {listWords(missing)}.
                        </p>
                    ) : null}
                    {point && d.recommendation_status === 'available' ? (
                        <section
                            className="place-sheet-way"
                            aria-labelledby="place-way-title"
                        >
                            <h3 id="place-way-title">Getting there</h3>
                            <div>
                                <span aria-hidden="true">
                                    <PlaceIcon name="route" />
                                </span>
                                <p>
                                    Travel plans the way from where you are,
                                    with today’s departures.
                                </p>
                                <Link
                                    className="place-way"
                                    href={timetable.url({
                                        query: { view: 'journey', place: d.id },
                                    })}
                                >
                                    Show the way →
                                </Link>
                            </div>
                        </section>
                    ) : null}
                    <p className="place-sheet-source">
                        From{' '}
                        <a
                            href={
                                facts.hours.source_url ??
                                'https://www.openstreetmap.org/'
                            }
                            target="_blank"
                            rel="noopener"
                        >
                            OpenStreetMap
                        </a>
                        {observed ? `, seen ${observed}` : ''}
                        {area
                            ? ' · a large area: its point is approximate'
                            : week
                              ? ' · hours as listed, not checked live'
                              : ''}
                        . Map © OpenStreetMap contributors, OpenFreeMap.
                    </p>
                    {d.photo_url && d.photo_attribution ? (
                        <p className="place-photo-credit">
                            {d.photo_source_url ? (
                                <a
                                    href={d.photo_source_url}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Photo: {d.photo_attribution}
                                </a>
                            ) : (
                                `Photo: ${d.photo_attribution}`
                            )}
                            {d.photo_license_url ? (
                                <>
                                    {' · '}
                                    <a
                                        href={d.photo_license_url}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Licence
                                    </a>
                                </>
                            ) : null}
                        </p>
                    ) : null}
                    {/* Plan a visit opens under the place (Slide) and folds back. */}
                    <section
                        ref={plan}
                        className="place-sheet-plan"
                        data-open={planning}
                        aria-label="Plan a visit"
                        inert={!planning}
                    >
                        <div className="place-sheet-plan-in">
                            {planning ? (
                                <div className="place-sheet-plan-inner">
                                    <h3>Plan a visit</h3>
                                    <PlanVisitPanel visit={visit} />
                                    <PlanVisitFooter
                                        visit={visit}
                                        onBack={() => setPlanning(false)}
                                    />
                                </div>
                            ) : null}
                        </div>
                    </section>
                </div>
            </div>
            <div className="place-sheet-actions">
                <button
                    type="button"
                    className="place-sheet-secondary"
                    aria-pressed={saved}
                    onClick={onSave}
                >
                    <PlaceIcon name="bookmark" />
                    <span>{saved ? 'Saved' : 'Save'}</span>
                </button>
                <button
                    type="button"
                    className="place-sheet-secondary"
                    onClick={() => void share()}
                >
                    <PlaceIcon name="share" />
                    <span>Share</span>
                </button>
                {d.recommendation_status !== 'unavailable' ? (
                    <button
                        type="button"
                        className={cn(
                            planning
                                ? 'place-sheet-secondary'
                                : 'place-sheet-primary',
                        )}
                        aria-expanded={planning}
                        onClick={() => setPlanning(!planning)}
                    >
                        {planning ? 'Hide planning' : 'Plan a visit'}
                    </button>
                ) : null}
            </div>
        </>
    );
}

/** Straight-line km between two points. */
function kmBetween(
    a: { lat: number; lng: number },
    b: { lat: number; lng: number },
): number {
    const rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad;
    const dLng = (b.lng - a.lng) * rad;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;

    return 6371 * 2 * Math.asin(Math.sqrt(h));
}
