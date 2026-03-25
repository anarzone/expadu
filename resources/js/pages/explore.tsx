import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FilterBar } from '@/components/explore/filter-bar';
import { SpotCard } from '@/components/explore/spot-card';
import AppLayout from '@/layouts/app-layout';

type SpotData = {
    id: number; name: string; category: string; address: string | null;
    wifi_speed: string | null; noise_level: string | null; time_limit_mins: number | null;
    rating: number | null; active_checkins_count: number;
};

type ExploreProps = {
    spots: { data: SpotData[] };
    filters: { category?: string | null; noise_level?: string | null; sort?: string };
    spot?: SpotData | null;
};

export default function Explore() {
    const { spots, filters, spot } = usePage<ExploreProps>().props;
    const [category, setCategory] = useState(filters.category || '');

    function filterByCategory(cat: string) {
        setCategory(cat);
        router.get('/explore', cat ? { category: cat } : {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Explore', href: '/explore' }]}>
            <Head title="Explore" />
            <div className="mx-auto w-full max-w-[680px]">
                <div className="border-b border-border px-6 py-4">
                    <h1 className="font-display text-xl font-medium">🗺️ Explore</h1>
                    <span className="text-xs text-muted-foreground">Discover work spots, cafés, and quiet places</span>
                </div>

                <FilterBar active={category} onChange={filterByCategory} />

                <div className="space-y-2 px-4 pb-6">
                    {spots.data.length === 0 ? (
                        <div className="py-12 text-center text-sm text-muted-foreground">No spots found for this filter.</div>
                    ) : (
                        spots.data.map((s) => <SpotCard key={s.id} spot={s} />)
                    )}
                </div>

                {spot && (
                    <div className="fixed inset-x-0 bottom-0 z-50 rounded-t-2xl border-t border-border bg-card p-5 shadow-lg md:static md:mx-4 md:mb-4 md:rounded-xl md:border md:shadow-none">
                        <div className="text-lg font-semibold">{spot.name}</div>
                        <div className="mt-1 text-sm text-muted-foreground">{spot.address}</div>
                        <div className="mt-3 flex gap-2">
                            <button
                                onClick={() => router.post(`/explore/${spot.id}/checkin`, {}, { preserveScroll: true })}
                                className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
                            >
                                Check in
                            </button>
                            <button
                                onClick={() => router.get('/explore', {}, { preserveState: true })}
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
