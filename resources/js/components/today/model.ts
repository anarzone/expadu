import type { Plan, Slot, Workspace } from '@/components/composer/types';
import type { EventOccurrence } from '@/components/events/types';

export type TodaySlot = Pick<Slot, 'id' | 'name' | 'start_time'> &
    Partial<Slot> & { location?: string | null };
export type SavedPlan = {
    date?: string | null;
    weekday?: string;
    prompt?: string | null;
    slots: TodaySlot[];
    feasibility_checked?: boolean;
};

export type Tile = {
    type: string;
    title: string;
    subtitle: string;
    severity: 'danger' | 'warn' | 'info' | 'neutral';
    score: number;
    href: string | null;
    key: string;
    meta: Record<string, unknown>;
};

export type RailCard = {
    id: string;
    name: string;
    veedel: string | null;
    category: string;
    cost: string | null;
    lat: number | null;
    lng: number | null;
    reason: string | null;
    kind: string;
    href: string | null;
    due_label?: string;
    urgency?: string;
    verified?: string | null;
};

export type Rail = { key: string; title: string; cards: RailCard[] };

/** A supplied closure for today invalidates the feed's generic shop-today nudge. */
export function relevantSignals(tiles: Tile[]): Tile[] {
    const holidayToday = tiles.some(
        (tile) =>
            tile.type === 'rhythm_warning' && tile.key === 'holiday_today',
    );

    return holidayToday
        ? tiles.filter(
              (tile) =>
                  tile.type !== 'rhythm_warning' ||
                  tile.key !== 'shops_closed_tomorrow',
          )
        : tiles;
}

export function dayKey(value: Date | string): string {
    const date = value instanceof Date ? value : new Date(value);

    if (!Number.isFinite(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Berlin',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
}

/** The durable workspace is authoritative, including an explicitly empty day. */
export function projectDay(
    workspace: Workspace | null,
    legacy: SavedPlan | null | undefined,
    date: string,
): {
    confirmed: SavedPlan | null;
    upcoming: SavedPlan[];
    draft: Plan | null;
    undated: SavedPlan | null;
} {
    if (workspace) {
        return {
            confirmed: workspace.plans[date] ?? null,
            upcoming: Object.values(workspace.plans)
                .filter((plan) => plan.date > date && plan.slots.length > 0)
                .sort((a, b) => a.date.localeCompare(b.date)),
            draft: workspace.draft,
            undated: null,
        };
    }

    return {
        confirmed: legacy?.date === date ? legacy : null,
        upcoming: legacy?.date && legacy.date > date ? [legacy] : [],
        draft: null,
        undated: legacy && !legacy.date ? legacy : null,
    };
}

export function partitionAgenda(slots: TodaySlot[], now: Date) {
    const current: TodaySlot[] = [];
    const earlier: TodaySlot[] = [];

    for (const slot of [...slots].sort((a, b) =>
        a.start_time.localeCompare(b.start_time),
    )) {
        const end = slot.end_at ? Date.parse(slot.end_at) : NaN;
        (Number.isFinite(end) && end <= now.getTime() ? earlier : current).push(
            slot,
        );
    }

    return { current, earlier };
}

/** No invented event duration or free-time inference. Pending changes also reserve context. */
export function availableEvents(
    events: EventOccurrence[],
    workspace: Workspace | null,
    legacy: SavedPlan | null | undefined,
    date: string,
    now: Date,
): EventOccurrence[] {
    const { confirmed, draft } = projectDay(workspace, legacy, date);
    const slots = [
        ...(confirmed?.slots ?? []),
        ...(draft?.date === date ? draft.slots : []),
    ];
    const seen = new Set<string>();

    return events.filter((event) => {
        const start = Date.parse(event.occurrence_start);
        const end = event.occurrence_end
            ? Date.parse(event.occurrence_end)
            : Infinity;
        const key = `${event.id}:${event.occurrence_start}`;

        if (
            seen.has(key) ||
            dayKey(event.occurrence_start) !== date ||
            start <= now.getTime() ||
            !Number.isFinite(start)
        ) {
            return false;
        }

        seen.add(key);

        return !slots.some((slot) => {
            if (slot.id === `event:${event.id}`) {
                return true;
            }

            const slotStart = slot.start_at ? Date.parse(slot.start_at) : NaN;
            const slotEnd = slot.end_at ? Date.parse(slot.end_at) : Infinity;

            return (
                Number.isFinite(slotStart) &&
                start < slotEnd &&
                (Number.isFinite(end) ? end : Infinity) > slotStart
            );
        });
    });
}

export function journeyContext(slot: TodaySlot, date: string) {
    if (
        slot.recommendation_status === 'unavailable' ||
        slot.lat == null ||
        slot.lng == null ||
        !Number.isFinite(slot.lat) ||
        !Number.isFinite(slot.lng) ||
        Math.abs(slot.lat) > 90 ||
        Math.abs(slot.lng) > 180 ||
        (slot.lat === 0 && slot.lng === 0) ||
        !slot.start_at ||
        dayKey(slot.start_at) !== date
    ) {
        return null;
    }

    return {
        to_name: slot.location || slot.name,
        to_lat: slot.lat,
        to_lng: slot.lng,
        to_id: slot.id,
        arrive_by: slot.start_at,
        activity: slot.id,
        plan_date: date,
        back_label: slot.name,
    };
}
