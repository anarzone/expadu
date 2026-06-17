export type SettlementProgressData = {
    total: number;
    completed: number;
    percent: number;
    situation: string | null;
    days_since_arrival: number;
};

export type YourPlacesData = {
    places: {
        id: number;
        emoji: string | null;
        name: string;
        address: string | null;
    }[];
};

export type QuickAccessData = {
    items: { emoji: string; label: string; href: string; subtitle: string }[];
};
