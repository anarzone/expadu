import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { EventCard } from '@/components/events/event-card';
import AppLayout from '@/layouts/app-layout';

type EventData = {
    id: number; title: string; emoji: string | null; category: string | null;
    starts_at: string; location_name: string | null; is_free: boolean;
    price: string | null; attendees_count: number; max_attendees: number | null;
};

const categories = ['', 'language', 'social', 'culture', 'sports', 'food'];
const categoryLabels: Record<string, string> = { '': 'All', language: 'Language', social: 'Social', culture: 'Culture', sports: 'Sports', food: 'Food' };

export default function Events() {
    const { events, filters, tab: activeTab, event: selectedEvent } = usePage<{
        events: { data: EventData[] };
        filters: { category?: string | null; free?: string | null };
        tab?: string;
        event?: EventData | null;
    }>().props;

    const [tab, setTab] = useState(activeTab || 'upcoming');
    const [category, setCategory] = useState(filters.category || '');

    function switchTab(t: string) {
        setTab(t);
        if (t === 'saved') {
            router.get('/events/saved', {}, { preserveState: true });
        } else {
            router.get('/events', category ? { category } : {}, { preserveState: true });
        }
    }

    function filterByCategory(cat: string) {
        setCategory(cat);
        router.get('/events', cat ? { category: cat } : {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Events', href: '/events' }]}>
            <Head title="Events" />
            <div className="mx-auto w-full max-w-[680px]">
                <div className="border-b border-border px-6 py-4">
                    <h1 className="font-display text-xl font-medium">📅 Events</h1>
                    <span className="text-xs text-muted-foreground">Local events, meetups, and things to do</span>
                </div>

                <div className="flex border-b border-border">
                    {['upcoming', 'saved'].map((t) => (
                        <button
                            key={t}
                            onClick={() => switchTab(t)}
                            className={`flex-1 border-b-2 px-3 py-3 text-center text-xs font-semibold capitalize transition-colors ${
                                tab === t ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:bg-secondary'
                            }`}
                        >
                            {t}
                        </button>
                    ))}
                </div>

                {tab === 'upcoming' && (
                    <div className="flex gap-2 overflow-x-auto px-4 py-3 scrollbar-none">
                        {categories.map((c) => (
                            <button
                                key={c}
                                onClick={() => filterByCategory(c)}
                                className={`shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition-all ${
                                    category === c ? 'border-primary bg-primary text-white' : 'border-border bg-card text-foreground hover:border-primary/30'
                                }`}
                            >
                                {categoryLabels[c]}
                            </button>
                        ))}
                    </div>
                )}

                <div className="space-y-2 px-4 pb-6">
                    {events.data.length === 0 ? (
                        <div className="py-12 text-center text-sm text-muted-foreground">
                            {tab === 'saved' ? "You haven't joined any events yet." : 'No events found.'}
                        </div>
                    ) : (
                        events.data.map((e) => <EventCard key={e.id} event={e} />)
                    )}
                </div>

                {selectedEvent && (
                    <div className="fixed inset-x-0 bottom-0 z-50 rounded-t-2xl border-t border-border bg-card p-5 shadow-lg md:static md:mx-4 md:mb-4 md:rounded-xl md:border md:shadow-none">
                        <div className="text-lg font-semibold">{selectedEvent.emoji} {selectedEvent.title}</div>
                        <div className="mt-1 text-sm text-muted-foreground">{selectedEvent.location_name}</div>
                        <div className="mt-3 flex gap-2">
                            <button
                                onClick={() => router.post(`/events/${selectedEvent.id}/join`, {}, { preserveScroll: true })}
                                className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
                            >
                                Join event
                            </button>
                            <button
                                onClick={() => router.get('/events', {}, { preserveState: true })}
                                className="rounded-lg bg-secondary px-4 py-2 text-sm font-medium"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
