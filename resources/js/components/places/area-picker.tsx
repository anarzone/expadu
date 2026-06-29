import { Deferred } from '@inertiajs/react';
import {
    IconChevronDown,
    IconFilter,
    IconMapPin,
    IconWorld,
} from '@tabler/icons-react';
import type { ReactNode } from 'react';
import { ICON_STROKE } from '@/constants/icons';

export type BezirkOption = {
    name: string;
    count: number;
    photo_url: string | null;
};

type Props = {
    areaLabel: ReactNode;
    /** 'all' | 'near' | a Bezirk name. */
    bezirk: string;
    veedel: string | null;
    railOptions: BezirkOption[];
    /** Stadtteile of the selected Bezirk (empty when 'all'). */
    chipOptions: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAllCologne: () => void;
    onNearMe: () => void;
    onPickBezirk: (name: string) => void;
    onPickVeedel: (veedel: string | null) => void;
};

/**
 * The shared two-level area picker: "All Cologne / Near me" globals, then the
 * Bezirk rail, then a drill into that Bezirk's Stadtteile. Presentational — the
 * page owns the selection state, so Places and the Composer share one picker.
 */
export function AreaPicker({
    areaLabel,
    bezirk,
    veedel,
    railOptions,
    chipOptions,
    open,
    onOpenChange,
    onAllCologne,
    onNearMe,
    onPickBezirk,
    onPickVeedel,
}: Props) {
    return (
        <div className="relative">
            <button
                onClick={() => onOpenChange(!open)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 rounded-full border border-border bg-card px-[15px] py-[9px] text-[13.5px] font-semibold text-foreground shadow-card transition-colors hover:border-primary sm:w-auto"
            >
                <IconFilter size={15} stroke={ICON_STROKE} />
                <span className="font-medium text-text-2">Searching in</span>
                {areaLabel}
                <IconChevronDown
                    size={13}
                    stroke={2}
                    className="ml-auto sm:ml-0"
                />
            </button>

            {open && (
                <>
                    <div
                        className="fixed inset-0 z-30"
                        onClick={() => onOpenChange(false)}
                    />
                    <div className="absolute top-12 left-0 z-40 w-[min(392px,calc(100vw-2rem))] rounded-[16px] border border-border bg-card p-4 shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                        <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                            Search area
                        </div>

                        <div className="mb-[13px] flex gap-2 border-b border-border pb-[13px]">
                            <button
                                onClick={onAllCologne}
                                className={`inline-flex items-center gap-[7px] rounded-full border px-[15px] py-[9px] text-[13px] font-semibold transition-colors ${
                                    bezirk === 'all'
                                        ? 'border-primary bg-primary text-white shadow-[0_2px_9px_rgba(255,57,2,0.3)]'
                                        : 'border-border bg-surface-2 text-foreground hover:border-primary'
                                }`}
                            >
                                <IconWorld size={14} stroke={ICON_STROKE} />
                                All Cologne
                            </button>
                            <button
                                onClick={onNearMe}
                                className={`inline-flex items-center gap-[7px] rounded-full border px-[15px] py-[9px] text-[13px] font-semibold transition-colors ${
                                    bezirk === 'near'
                                        ? 'border-primary bg-primary text-white shadow-[0_2px_9px_rgba(255,57,2,0.3)]'
                                        : 'border-border bg-surface-2 text-foreground hover:border-primary'
                                }`}
                            >
                                <IconMapPin size={14} stroke={ICON_STROKE} />
                                Near me
                            </button>
                        </div>

                        <Deferred
                            data="bezirke"
                            fallback={
                                <div className="flex gap-[7px]">
                                    {[1, 2, 3, 4, 5].map((i) => (
                                        <div
                                            key={i}
                                            className="h-8 w-20 animate-pulse rounded-full bg-secondary"
                                        />
                                    ))}
                                </div>
                            }
                        >
                            <div className="flex flex-wrap gap-[7px]">
                                {railOptions.map((b) => {
                                    const on = bezirk === b.name;

                                    return (
                                        <button
                                            key={b.name}
                                            onClick={() => onPickBezirk(b.name)}
                                            className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                on
                                                    ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                    : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                            }`}
                                        >
                                            {b.name}
                                        </button>
                                    );
                                })}
                            </div>
                        </Deferred>

                        {chipOptions.length > 0 && (
                            <div className="mt-[14px] border-t border-dashed border-border pt-[14px]">
                                <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                                    ↳ Neighbourhoods in {bezirk}
                                </div>
                                <div className="flex flex-wrap gap-[7px]">
                                    {[null, ...chipOptions].map((name) => {
                                        const on = veedel === name;

                                        return (
                                            <button
                                                key={name ?? 'all'}
                                                data-veedel-chip={name ?? ''}
                                                onClick={() =>
                                                    onPickVeedel(name)
                                                }
                                                className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                    on
                                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                                }`}
                                            >
                                                {name ?? `All of ${bezirk}`}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
