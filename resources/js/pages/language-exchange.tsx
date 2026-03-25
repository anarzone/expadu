import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function LanguageExchange() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Language Exchange', href: '/language-exchange' }]}>
            <Head title="Language Exchange" />
            <div className="flex flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
                <span className="text-6xl">🗣️</span>
                <h1 className="font-display text-2xl font-medium">Language Exchange</h1>
                <p className="max-w-md text-muted-foreground">
                    Find language partners matched to your level.
                </p>
                <span className="rounded-full bg-accent-soft px-4 py-1.5 text-sm font-medium text-primary">
                    Coming soon
                </span>
            </div>
        </AppLayout>
    );
}
