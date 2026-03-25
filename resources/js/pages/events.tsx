import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function Events() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Events', href: '/events' }]}>
            <Head title="Events" />
            <div className="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                <span className="text-6xl">📅</span>
                <h1 className="font-display text-2xl font-medium">Events</h1>
                <p className="max-w-md text-muted-foreground">
                    Local events, meetups, and things happening in your city.
                </p>
                <span className="rounded-full bg-accent-soft px-4 py-1.5 text-sm font-medium text-primary">
                    Coming soon
                </span>
            </div>
        </AppLayout>
    );
}
