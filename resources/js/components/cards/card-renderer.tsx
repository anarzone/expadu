import { BlueHighlightCard } from '@/components/cards/blue-highlight-card';
import { DisruptionBanner } from '@/components/cards/disruption-banner';
import { FeedSection } from '@/components/cards/feed-section';
import { PlacesChips } from '@/components/cards/places-chips';
import { ProgressCard } from '@/components/cards/progress-card';
import { QuickAccessGrid } from '@/components/cards/quick-access-grid';
import type {
    BlueHighlightData,
    DisruptionBannerData,
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
                    case 'disruption_banner':
                        return (
                            <FeedSection key={index}>
                                <DisruptionBanner data={card.data as unknown as DisruptionBannerData} />
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
                        if ((card.data as { placeholder?: boolean }).placeholder) {
                            return (
                                <FeedSection key={index} label="This Week">
                                    <div className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                        Events coming in Phase 2
                                    </div>
                                </FeedSection>
                            );
                        }
                        return null;
                    case 'live_departures': {
                        const depData = card.data as {
                            departures: { line: string; direction: string; color: string; minutes: number }[];
                            source?: string;
                            stop_name?: string;
                            placeholder?: boolean;
                        };
                        if (depData.placeholder || !depData.departures?.length) {
                            return (
                                <FeedSection key={index} label="Live Departures">
                                    <div className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                        No departures available right now
                                    </div>
                                </FeedSection>
                            );
                        }
                        const srcLabel =
                            depData.source === 'gtfs_static'
                                ? 'Static timetable'
                                : depData.source === 'gtfs_rt'
                                  ? 'Live'
                                  : 'Scheduled times';
                        return (
                            <FeedSection key={index} label="Live Departures">
                                <div
                                    style={{
                                        background: '#FFFFFF',
                                        border: '1px solid #E2DFD6',
                                        borderRadius: 14,
                                        overflow: 'hidden',
                                    }}
                                >
                                    {/* Header */}
                                    <div
                                        className="flex items-center justify-between"
                                        style={{
                                            padding: '10px 14px',
                                            borderBottom: '1px solid #E2DFD6',
                                            background: '#EFEDE7',
                                        }}
                                    >
                                        <span style={{ fontSize: 12, fontWeight: 700 }}>
                                            📍 {depData.stop_name ?? 'Ehrenfeld'}
                                        </span>
                                        <span
                                            style={{
                                                fontSize: 9,
                                                fontWeight: 600,
                                                textTransform: 'uppercase',
                                                letterSpacing: '0.06em',
                                                color: '#AAA89F',
                                            }}
                                        >
                                            {srcLabel}
                                        </span>
                                    </div>
                                    {/* Rows */}
                                    {depData.departures.map((dep, i) => (
                                        <div
                                            key={i}
                                            className="flex items-center gap-3"
                                            style={{
                                                padding: '10px 14px',
                                                borderBottom:
                                                    i < depData.departures.length - 1
                                                        ? '1px solid #E2DFD6'
                                                        : 'none',
                                            }}
                                        >
                                            {/* Line badge */}
                                            <div
                                                className="flex shrink-0 items-center justify-center"
                                                style={{
                                                    width: 30,
                                                    height: 30,
                                                    borderRadius: 7,
                                                    background: dep.color,
                                                    color: 'white',
                                                    fontFamily: "'Geist Mono', monospace",
                                                    fontSize: dep.line.length > 2 ? 9 : 11,
                                                    fontWeight: 700,
                                                }}
                                            >
                                                {dep.line}
                                            </div>
                                            {/* Direction */}
                                            <div className="min-w-0 flex-1">
                                                <div
                                                    style={{
                                                        fontSize: 13,
                                                        fontWeight: 600,
                                                        whiteSpace: 'nowrap',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                    }}
                                                >
                                                    {dep.direction}
                                                </div>
                                            </div>
                                            {/* Minutes */}
                                            <div className="shrink-0 text-right">
                                                <span
                                                    style={{
                                                        fontFamily: "'Geist Mono', monospace",
                                                        fontSize: 18,
                                                        fontWeight: 500,
                                                        color: '#0A7C52',
                                                    }}
                                                >
                                                    {dep.minutes}
                                                </span>
                                                <span
                                                    style={{
                                                        fontSize: 10,
                                                        color: '#AAA89F',
                                                        marginLeft: 2,
                                                    }}
                                                >
                                                    min
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </FeedSection>
                        );
                    }
                    default:
                        return null;
                }
            })}
        </div>
    );
}
