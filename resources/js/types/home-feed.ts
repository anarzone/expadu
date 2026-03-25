export type HomeCard = {
    type: string;
    data: Record<string, unknown>;
    priority: number;
};

export type BlueHighlightData = {
    urgent_task: { id: number; title: string; urgency: string; deadline_days: number | null } | null;
    appointment: { id: number; office_name: string; scheduled_at: string } | null;
    headline: string;
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
