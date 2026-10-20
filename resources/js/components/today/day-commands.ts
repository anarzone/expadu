import { router } from '@inertiajs/react';
import { api, ApiError } from '@/components/composer/types';
import { show, update } from '@/routes/composer/workspace';

/*
 * Today's changes to the day go through the one workspace (Composer's
 * WorkspaceStore), never a second plan store. Each applies directly; a
 * refusal (a clash, outside listed hours, a fixed time) changes nothing
 * and says why. After each, Today re-reads its day, whose leave-by and
 * links the server works out.
 */

type Change = {
    id: string;
    date: string;
    label: string;
    undoable: boolean;
};

type Workspace = { revision: number; changes?: Change[] };

type Command =
    | { action: 'remove'; id: string; date: string }
    | {
          action: 'appointment';
          name: string;
          date: string;
          start: string;
          end: string;
          location?: string;
      }
    | { action: 'edit'; id: string; date: string; start: string; end: string }
    | { action: 'complete' | 'uncomplete'; id: string; date: string }
    | { action: 'undo'; change: string };

const reread = () => router.reload({ only: ['day'], preserveUrl: true });

/**
 * Sends one change. Returns the change it made (for Undo), if any. A stale
 * revision (someone changed the plan meanwhile) re-reads and tries once
 * more with the current one.
 */
export async function changeDay(
    revision: number,
    command: Command,
): Promise<Change | null> {
    try {
        const workspace = await api<Workspace>(update.url(), {
            revision,
            ...command,
        });

        return workspace.changes?.at(-1) ?? null;
    } catch (reason) {
        if (reason instanceof ApiError && reason.status === 409) {
            const current = await api<Workspace>(show.url());
            const workspace = await api<Workspace>(update.url(), {
                revision: current.revision,
                ...command,
            });

            return workspace.changes?.at(-1) ?? null;
        }

        throw reason;
    } finally {
        reread();
    }
}

/** What failed, in words a person can act on. */
export function failure(reason: unknown): string {
    return reason instanceof Error && reason.message
        ? reason.message
        : 'That change could not be saved. Try again.';
}
