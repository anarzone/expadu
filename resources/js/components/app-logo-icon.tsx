import type { HTMLAttributes } from 'react';

export default function AppLogoIcon(props: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            className={`flex size-[34px] shrink-0 items-center justify-center rounded-[10px] bg-primary font-display text-lg text-white ${props.className ?? ''}`}
        >
            E
        </div>
    );
}
