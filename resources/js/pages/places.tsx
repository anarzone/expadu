import { Head } from '@inertiajs/react';
import { IconCompass } from '@tabler/icons-react';
import AppLayout from '@/layouts/app-layout';

/**
 * Places placeholder. The v1 maps-clone (explore.tsx + SpotController) is
 * kept in the codebase but unrouted — Places will be redesigned as a
 * list-first, curated discovery feed alongside the Events page.
 */
export default function Places() {
    return (
        <AppLayout>
            <Head title="Places" />
            <div className="mx-auto flex w-full max-w-[600px] flex-1 flex-col items-center justify-center px-6 py-24 text-center">
                <div className="mb-5 flex size-14 items-center justify-center rounded-2xl bg-accent-soft text-primary">
                    <IconCompass size={28} stroke={1.6} />
                </div>
                <h1 className="mb-2 font-display text-[26px] font-medium tracking-tight">
                    Places is coming soon
                </h1>
                <p className="max-w-[360px] text-[15px] leading-relaxed text-muted-foreground">
                    We're curating Cologne's parks, pitches, courts and lakes —
                    the spots worth showing up for, by Veedel. Check back soon.
                </p>
            </div>
        </AppLayout>
    );
}
