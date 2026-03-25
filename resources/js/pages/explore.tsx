import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function Explore() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Explore', href: '/explore' }]}>
            <Head title="Explore" />
            <div className="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                <span className="text-6xl">🗺️</span>
                <h1 className="font-display text-2xl font-medium">Explore</h1>
                <p className="max-w-md text-muted-foreground">
                    Discover cafés, coworking spaces, and hidden gems in your city.
                </p>
                <span className="rounded-full bg-accent-soft px-4 py-1.5 text-sm font-medium text-primary">
                    Coming soon
                </span>
            </div>
        </AppLayout>
    );
}
