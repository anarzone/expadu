export type HomeCard = {
    type: string;
    data: Record<string, unknown>;
    priority: number;
};

export type BlueHighlightData = {
    urgent_task: { id: number; title: string; urgency: string; deadline_days: number | null; documents_required: string[] } | null;
    appointment: { id: number; office_name: string; scheduled_at: string; notes: string | null; is_tomorrow: boolean; time: string } | null;
    headline: string;
    timeline_rows: { emoji: string; title: string; subtitle: string; value: string; unit: string }[];
};

export type SettlementProgressData = {
    total: number;
    completed: number;
    percent: number;
    situation: string | null;
    days_since_arrival: number;
};

export type YourPlacesData = {
    places: { id: number; emoji: string | null; name: string; address: string | null }[];
};

export type QuickAccessData = {
    items: { emoji: string; label: string; href: string; subtitle: string }[];
};

export type DisruptionBannerData = {
    title: string;
    description: string;
    severity: string;
    lines: string[];
};
