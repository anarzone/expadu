/* One place, one sheet (the mock's connected/place-sheet.js, About): what
   the listing records about a place, "Not listed" for the rest, its
   listed hours for the week and where the facts come from, with Save for
   later and the place on a map. Around here opens it from a row; Composer
   (CO3) opens it by a place's id. Plan a visit and Get there arrive with
   AH2, on the props below. */

import { useEffect, useState } from 'react';
import { PlaceIcon } from '@/components/around-here/place-icon';
import { cuisineNames, dietText } from '@/components/around-here/place-model';
import { api } from '@/components/composer/types';
import { Sheet } from '@/components/ui/sheet';
import { showToast } from '@/components/ui/toast';
import './place-sheet.css';

export type PlaceSheetProps = {
    /** The place shown, by its id; null closes the sheet. */
    place: number | null;
    /** The time its listed hours are read at, HH:MM today (now by default). */
    at?: string;
    /** Where you'd come from (Around here's point): AH2's Get there starts there. */
    from?: { name: string; lat: number; lng: number } | null;
    /** The part it opens on; AH2 adds 'plan' and 'way'. */
    initial?: 'about';
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
    hours_at?:
        | { state: 'open'; until: string }
        | { state: 'closed'; opens?: string; today?: boolean }
        | { state: 'unknown' };
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
const OUTDOOR = ['park', 'playground', 'nature'];
const DAYS = [
    ['mon', 'Mon'],
    ['tue', 'Tue'],
    ['wed', 'Wed'],
    ['thu', 'Thu'],
    ['fri', 'Fri'],
    ['sat', 'Sat'],
    ['sun', 'Sun'],
] as const;

const known = (fact: Fact | undefined) =>
    fact && fact.status === 'known' && fact.value ? fact.value : null;

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

function Row({
    label,
    value,
    href,
}: {
    label: string;
    value: string | null;
    href?: string;
}) {
    return (
        <div>
            <dt>{label}</dt>
            <dd className={value ? undefined : 'is-unknown'}>
                {value ? (
                    href ? (
                        <a href={href} target="_blank" rel="noopener">
                            {value}
                        </a>
                    ) : (
                        value
                    )
                ) : (
                    'Not listed'
                )}
            </dd>
        </div>
    );
}

export function PlaceSheet({ place, at, onClose, onSaved }: PlaceSheetProps) {
    const [detail, setDetail] = useState<Detail | null>(null);
    // The place whose details couldn't load, if it's the one asked for.
    const [failedFor, setFailedFor] = useState<number | null>(null);
    const [saved, setSaved] = useState(false);

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
    const facts = d?.place_facts;
    const kind = d?.kind_icon ?? '';
    const area = kind === 'nature';
    const practical = facts?.practical ?? {};
    const website = websiteOf(known(facts?.contact.website));
    const phone = known(facts?.contact.phone);
    const week = facts?.hours.status === 'known' ? facts.hours.parsed : null;
    const observed = facts?.hours.observed_at
        ? new Date(facts.hours.observed_at).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              timeZone: 'Europe/Berlin',
          })
        : null;
    const wheelchair = known(practical.wheelchair);
    const outdoor = known(practical.outdoor_seating);
    const wifi = known(practical.internet_access);

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
            className="place-sheet"
            title={d?.name ?? ''}
            footer={
                d ? (
                    <div className="place-sheet-footer">
                        <button
                            type="button"
                            className="place-sheet-save"
                            aria-pressed={saved}
                            onClick={save}
                        >
                            {saved ? 'Saved' : 'Save for later'}
                        </button>
                    </div>
                ) : null
            }
        >
            {failed ? (
                <p className="place-source">
                    This place couldn’t open. Please try again.
                </p>
            ) : d && facts ? (
                <>
                    <div className="place-sheet-hero">
                        <span>{d.fine_label}</span>
                        <strong>{d.veedel}</strong>
                        {d.lat !== null && d.lng !== null ? (
                            <a
                                href={`https://www.openstreetmap.org/?mlat=${d.lat}&mlon=${d.lng}#map=17/${d.lat}/${d.lng}`}
                                target="_blank"
                                rel="noopener"
                            >
                                View on map ↗
                            </a>
                        ) : null}
                    </div>
                    <dl className="place-facts">
                        {area ? null : (
                            <Row label="Today" value={todayText(d.hours_at)} />
                        )}
                        {area ? null : (
                            <Row
                                label="Address"
                                value={known(facts.contact.address)}
                            />
                        )}
                        {['restaurant', 'fastfood'].includes(kind) ||
                        (EATING.includes(kind) &&
                            cuisineNames(known(practical.cuisine))) ? (
                            <Row
                                label="Cuisine"
                                value={
                                    cuisineNames(known(practical.cuisine)) ||
                                    null
                                }
                            />
                        ) : null}
                        {EATING.includes(kind) ? (
                            <Row
                                label="Outdoor seating"
                                value={
                                    outdoor
                                        ? outdoor.toLowerCase() === 'no'
                                            ? 'No'
                                            : outdoor.toLowerCase() === 'yes'
                                              ? 'Yes'
                                              : `Yes, ${outdoor.replace(/_/g, ' ')}`
                                        : null
                                }
                            />
                        ) : null}
                        {EATING.includes(kind) ? (
                            <Row
                                label="Diet"
                                value={
                                    dietText({
                                        vegetarian: known(
                                            practical['diet:vegetarian'],
                                        ),
                                        vegan: known(practical['diet:vegan']),
                                    }) || null
                                }
                            />
                        ) : null}
                        {kind === 'library' ? (
                            <Row
                                label="Wi-Fi"
                                value={
                                    wifi
                                        ? ({
                                              wlan: 'Yes',
                                              yes: 'Yes',
                                              terminal: 'Public computers',
                                              no: 'No',
                                          }[wifi] ?? null)
                                        : null
                                }
                            />
                        ) : null}
                        <Row
                            label="Wheelchair"
                            value={
                                wheelchair
                                    ? ({
                                          yes: 'Step-free access',
                                          limited: 'Partly step-free',
                                          no: 'Not step-free',
                                      }[wheelchair] ?? wheelchair)
                                    : null
                            }
                        />
                        {OUTDOOR.includes(kind) ? (
                            <Row
                                label="Access"
                                value={
                                    known(facts.access)
                                        ? ({
                                              yes: 'Public',
                                              public: 'Public',
                                              customers: 'Customers only',
                                              private: 'Private',
                                          }[known(facts.access)!] ??
                                          known(facts.access))
                                        : null
                                }
                            />
                        ) : null}
                        {OUTDOOR.includes(kind) || kind === 'culture' ? (
                            <Row
                                label="Entry fee"
                                value={
                                    known(facts.fee)
                                        ? ({
                                              no: 'Free',
                                              free: 'Free',
                                              yes: 'Paid',
                                              paid: 'Paid',
                                          }[known(facts.fee)!] ??
                                          known(facts.fee))
                                        : null
                                }
                            />
                        ) : null}
                        <Row
                            label="Website"
                            value={website?.text ?? null}
                            href={website?.href}
                        />
                        <Row
                            label="Phone"
                            value={phone}
                            href={
                                phone
                                    ? `tel:${phone.replace(/\s/g, '')}`
                                    : undefined
                            }
                        />
                    </dl>
                    {week ? (
                        <details className="place-week">
                            <summary>All listed hours</summary>
                            <dl>
                                {DAYS.map(([key, name]) => (
                                    <div key={key}>
                                        <dt>{name}</dt>
                                        <dd>
                                            {(week[key] ?? []).length
                                                ? week[key]
                                                      .map(
                                                          ([a, b]) =>
                                                              `${a}–${b}`,
                                                      )
                                                      .join(', ')
                                                : 'Closed'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </details>
                    ) : null}
                    <p className="place-source">
                        {area
                            ? 'A large area: its point and distances are approximate.'
                            : 'Listed hours, not a live check.'}{' '}
                        Place data from{' '}
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
                        {observed ? `, observed ${observed}` : ''}. Map ©
                        OpenStreetMap contributors, OpenFreeMap.
                    </p>
                </>
            ) : (
                <p className="place-source" role="status">
                    <PlaceIcon name="pin" /> Loading the place
                </p>
            )}
        </Sheet>
    );
}
