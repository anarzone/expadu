import { PersonaSwitcher } from '@/components/qa/persona-switcher';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

/**
 * A page's frame inside the persistent shell (layouts/app-shell-layout.tsx):
 * its <main> on the shared column, 1440px wide with 32px sides from 701px
 * (connected/page-frame.css). A page awaiting its rebuild passes `fullWidth`
 * and keeps its own column.
 */
export default function AppLayout({ children, fullWidth }: AppLayoutProps) {
    return (
        <>
            <main
                id="main"
                tabIndex={-1}
                className={cn(!fullWidth && 'page-frame', 'outline-none')}
            >
                {children}
            </main>
            <PersonaSwitcher />
        </>
    );
}
