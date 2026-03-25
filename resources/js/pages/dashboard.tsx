import { Head, usePage } from '@inertiajs/react';
import { CardRenderer } from '@/components/cards/card-renderer';
import AppLayout from '@/layouts/app-layout';
import type { HomeCard } from '@/types/home-feed';

export default function Dashboard() {
    const { cards } = usePage<{ cards: HomeCard[] }>().props;
    const { auth } = usePage().props;
    const user = auth?.user as { name?: string } | undefined;

    const greeting = getGreeting(user?.name);

    return (
        <AppLayout>
            <Head title="Home" />
            <div className="mx-auto w-full max-w-[680px]">
                <div className="sticky top-0 z-50 flex items-center justify-between border-b border-border bg-background/[0.92] px-6 py-4 backdrop-blur-2xl">
                    <span className="font-display text-xl font-medium tracking-tight">{greeting}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {new Date().toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' }).toUpperCase()}
                    </span>
                </div>

                <CardRenderer cards={cards} />
            </div>
        </AppLayout>
    );
}

function getGreeting(name?: string): string {
    const hour = new Date().getHours();
    const display = name ? `, ${name}` : '';

    if (hour < 12) return `Good morning${display} ☀️`;
    if (hour < 18) return `Good afternoon${display} 👋`;
    return `Good evening${display} 🌙`;
}
