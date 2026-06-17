import { Head } from '@inertiajs/react';
import { CategoryIllustration } from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import { PlaceDetail } from '@/components/places/place-detail';
import type { Place } from '@/components/places/types';
import AppLayout from '@/layouts/app-layout';

const SAMPLE: Place = {
    id: 1,
    name: 'Grüngürtel basketball court',
    category: 'court',
    fine_label: 'Basketball court',
    emoji: '🏀',
    veedel: 'Ehrenfeld',
    park: 'Grüngürtel',
    lat: 50.95,
    lng: 6.91,
    photo_url: null,
    photo_attribution: null,
    distance_min: 8,
    open_now: true,
    opening_hours_text: 'Open 24 h · floodlights until 22:00',
    price_text: 'free',
    feature_chips: ['full court', 'floodlit'],
    tip: 'Best in the afternoon — quiet before 17:00, busy after',
    tip_is_generic: false,
    cluster_size: 3,
    activities: [],
    transit_hint: 'Line 5 to Lenauplatz, then 3 min walk',
    facts: [
        { label: 'hoops', value: '2' },
        { label: 'surface', value: 'Tarmac' },
        { label: 'water nearby', value: 'Yes' },
    ],
    feedback_state: null,
    feedback_rating: null,
};

const MINIMAL: Place = {
    ...SAMPLE,
    id: 2,
    name: 'Bolzplatz Vogelsanger Str. with a deliberately long name to test the two-line clamp',
    category: 'pitch',
    fine_label: 'Pitch',
    emoji: '⚽',
    distance_min: null,
    open_now: null,
    price_text: null,
    feature_chips: [],
    tip: null,
    cluster_size: 1,
    park: null,
    facts: [],
};

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="mb-10">
            <h2 className="mb-3 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">
                {title}
            </h2>
            {children}
        </section>
    );
}

/**
 * Dev-only showcase for the shared ContentCard skeleton and its variants —
 * the living reference for future event/home-tile variants. Not routable
 * in production.
 */
export default function DevCards() {
    return (
        <AppLayout fullWidth>
            <Head title="Card showcase" />
            <div className="mx-auto h-full w-full max-w-[1120px] overflow-y-auto px-4 pt-6 pb-24 md:px-8">
                <h1 className="mb-8 font-display text-[26px] font-medium tracking-tight">
                    Card showcase
                </h1>

                <Section title="Place variant — full / minimal / expanded">
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <ContentCard
                            variant="place"
                            coarse={SAMPLE.category}
                            title={SAMPLE.name}
                            emoji={SAMPLE.emoji ?? undefined}
                            meta="Basketball court · Ehrenfeld · 8 min away"
                            chips={[
                                { label: 'open now', tone: 'open' },
                                { label: 'free', tone: 'price' },
                                { label: 'floodlit', tone: 'feature' },
                                { label: '×3 here', tone: 'feature' },
                            ]}
                            tip={SAMPLE.tip}
                            onActivate={() => {}}
                            action={
                                <span className="rounded-[9px] bg-accent-soft px-3 py-1.5 text-[13px] font-semibold text-primary">
                                    → Take me there
                                </span>
                            }
                        />
                        <ContentCard
                            variant="place"
                            coarse={MINIMAL.category}
                            title={MINIMAL.name}
                            emoji={MINIMAL.emoji ?? undefined}
                            meta="Pitch · Ehrenfeld"
                            onActivate={() => {}}
                        />
                        <ContentCard
                            variant="place"
                            coarse={SAMPLE.category}
                            title={SAMPLE.name}
                            emoji={SAMPLE.emoji ?? undefined}
                            meta="Basketball court · Ehrenfeld · 8 min away"
                            chips={[{ label: 'open now', tone: 'open' }]}
                            expanded
                            onActivate={() => {}}
                        >
                            <div className="border-t border-border px-4 py-4">
                                <PlaceDetail place={SAMPLE} />
                            </div>
                        </ContentCard>
                    </div>
                </Section>

                <Section title="Veedel variant — seeded / active / default visual">
                    <div className="flex gap-3 overflow-x-auto pb-1">
                        <ContentCard
                            variant="veedel"
                            coarse="veedel"
                            seed={null}
                            title="All Cologne"
                            onActivate={() => {}}
                        />
                        {['Ehrenfeld', 'Nippes', 'Kalk', 'Lindenthal'].map(
                            (name, i) => (
                                <ContentCard
                                    key={name}
                                    variant="veedel"
                                    coarse="veedel"
                                    title={name}
                                    count={(i + 1) * 17}
                                    active={i === 0}
                                    onActivate={() => {}}
                                />
                            ),
                        )}
                    </div>
                </Section>

                <Section title="Category illustrations">
                    <div className="grid grid-cols-3 gap-3 md:grid-cols-6">
                        {[
                            'park',
                            'pitch',
                            'court',
                            'swimming',
                            'playground',
                            'dog_park',
                        ].map((coarse) => (
                            <div key={coarse}>
                                <CategoryIllustration
                                    coarse={coarse}
                                    className="h-20 w-full rounded-xl"
                                />
                                <div className="mt-1 text-center font-mono text-[10px] text-muted-foreground">
                                    {coarse}
                                </div>
                            </div>
                        ))}
                    </div>
                </Section>
            </div>
        </AppLayout>
    );
}
