/* Adding something to the person's plan from where it's shown (an event's
   details, a place's sheet): Composer's workspace (`add`, on a day, at a
   time when one is chosen), with the Undo its change carries. Nothing here
   plans anything itself: the workspace decides, and a refusal (it clashes,
   it's full, it isn't open then, it's gone) comes back in its own words. */

import ComposerWorkspaceController from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { api, ApiError } from '@/components/composer/types';
import type { EventOccurrence } from '@/components/events/types';

type Slot = { id: string };
export type Change = {
    id: string;
    date: string;
    label: string;
    undoable: boolean;
    undone_at: string | null;
};

export type Workspace = {
    revision: number;
    plans: Record<string, { slots?: Slot[] } | undefined>;
    changes: Change[];
};

/** The workspace's id for an event: a repeating one names its occurrence
    (CandidateRepository). */
export const candidateFor = (o: EventOccurrence) =>
    o.is_recurring
        ? `event:${o.id}:${Math.floor(Date.parse(o.occurrence_start) / 1000)}`
        : `event:${o.id}`;

export const readPlan = () =>
    api<Workspace>(ComposerWorkspaceController.show.url());

export const inPlan = (
    workspace: Workspace | null,
    date: string,
    candidate: string,
) => !!workspace?.plans[date]?.slots?.some((s) => s.id === candidate);

const command = (body: Record<string, unknown>) =>
    api<Workspace>(ComposerWorkspaceController.update.url(), body);

/** Adds a candidate (`event:…`, `spot:…`) to a day's plan, at `times`
    when chosen; once more if another tab moved on. */
export async function addToPlan(
    candidate: string,
    date: string,
    revision: number,
    times?: { start: string; end: string },
): Promise<{ workspace: Workspace; change: Change | null }> {
    const body = { action: 'add', candidate, date, ...times };
    let workspace: Workspace;

    try {
        workspace = await command({ ...body, revision });
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 409) {
            throw error;
        }

        workspace = await command({
            ...body,
            revision: (await readPlan()).revision,
        });
    }

    // The workspace lists its changes oldest first: this add is the newest
    // one on that day.
    return {
        workspace,
        change:
            workspace.changes.findLast(
                (c) => c.date === date && c.undone_at === null,
            ) ?? null,
    };
}

export const undoChange = (change: string, revision: number) =>
    command({ action: 'undo', change, revision });
