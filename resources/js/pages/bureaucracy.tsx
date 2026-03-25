import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function Bureaucracy() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Bureaucracy', href: '/bureaucracy' }]}>
            <Head title="Bureaucracy" />
            <div className="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                <span className="text-6xl">🏛️</span>
                <h1 className="font-display text-2xl font-medium">Bureaucracy</h1>
                <p className="max-w-md text-muted-foreground">
                    Your personalised checklist for settling in Germany.
                </p>
                <span className="rounded-full bg-accent-soft px-4 py-1.5 text-sm font-medium text-primary">
                    Coming soon
                </span>
            </div>
        </AppLayout>
    );
}
