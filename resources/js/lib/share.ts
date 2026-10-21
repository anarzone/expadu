import { useSyncExternalStore } from 'react';
import { signal } from '@/lib/signals';
import type { SignalItemType, SignalSurface } from '@/lib/signals';

/*
 * Share (#210): the device's own share sheet, offered only where there is
 * one (phones, mostly). The `shared` signal goes once the sheet reports it
 * was shared; closing it without sharing sends nothing.
 */

const never = () => () => {};

/** Whether this device has a share sheet; false while drawn on the server. */
export function useCanShare(): boolean {
    return useSyncExternalStore(
        never,
        () => typeof navigator.share === 'function',
        () => false,
    );
}

export function shareItem(
    data: { title: string; text?: string; url: string },
    item: {
        surface: SignalSurface;
        item_type: SignalItemType;
        item_id: number;
    },
) {
    navigator
        .share(data)
        .then(() => signal({ action: 'shared', ...item }))
        .catch(() => {});
}
