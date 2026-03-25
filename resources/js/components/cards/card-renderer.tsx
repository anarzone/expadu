import { BlueHighlightCard } from '@/components/cards/blue-highlight-card';
import { FeedSection } from '@/components/cards/feed-section';
import { PlacesChips } from '@/components/cards/places-chips';
import { ProgressCard } from '@/components/cards/progress-card';
import { QuickAccessGrid } from '@/components/cards/quick-access-grid';
import type {
    BlueHighlightData,
    HomeCard,
    QuickAccessData,
    SettlementProgressData,
    YourPlacesData,
} from '@/types/home-feed';

export function CardRenderer({ cards }: { cards: HomeCard[] }) {
    return (
        <div>
            {cards.map((card, index) => {
                switch (card.type) {
                    case 'blue_highlight':
                        return (
                            <FeedSection key={index}>
                                <BlueHighlightCard data={card.data as unknown as BlueHighlightData} />
                            </FeedSection>
                        );
                    case 'settlement_progress':
                        return (
                            <FeedSection key={index} label="Settlement">
                                <ProgressCard data={card.data as unknown as SettlementProgressData} />
                            </FeedSection>
                        );
                    case 'your_places':
                        return (
                            <FeedSection key={index} label="Your Places">
                                <PlacesChips data={card.data as unknown as YourPlacesData} />
                            </FeedSection>
                        );
                    case 'quick_access':
                        return (
                            <FeedSection key={index} label="Quick Access">
                                <QuickAccessGrid data={card.data as unknown as QuickAccessData} />
                            </FeedSection>
                        );
                    case 'this_week':
                    case 'live_departures':
                        if ((card.data as { placeholder?: boolean }).placeholder) {
                            return (
                                <FeedSection
                                    key={index}
                                    label={card.type === 'this_week' ? 'This Week' : 'Live Departures'}
                                >
                                    <div className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                        {card.type === 'this_week'
                                            ? 'Events coming in Phase 2'
                                            : 'Transit data coming in Phase 2'}
                                    </div>
                                </FeedSection>
                            );
                        }
                        return null;
                    default:
                        return null;
                }
            })}
        </div>
    );
}
