import { IconChevronDown } from '@tabler/icons-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** Compact, keyboard-accessible anchored choice shared by Travel filters. */
export function TravelChoice({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: Array<{ value: string; label: string }>;
    onChange: (value: string) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    className="inline-flex min-h-11 max-w-full items-center gap-2 rounded-xl border border-border bg-card px-3 text-sm text-foreground transition-colors hover:bg-secondary focus-visible:outline-2 focus-visible:outline-cyan active:bg-cyan/10 motion-reduce:transition-none"
                >
                    <span className="truncate">
                        {options.find((option) => option.value === value)
                            ?.label ?? label}
                    </span>
                    <IconChevronDown
                        size={16}
                        className="shrink-0 text-muted-foreground"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-[min(420px,70dvh)] max-w-[calc(100vw-2rem)] min-w-48 overflow-y-auto rounded-2xl border-border bg-card p-1.5 shadow-lg motion-reduce:animate-none"
            >
                <DropdownMenuRadioGroup value={value} onValueChange={onChange}>
                    {options.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                            className="min-h-11 rounded-lg pr-4 text-sm focus:bg-cyan/10 focus:text-foreground data-[state=checked]:bg-cyan/10 data-[state=checked]:text-cyan-h"
                        >
                            {option.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
