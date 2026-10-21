/* The From search's two shapes, kept for Composer after Places' old page
   went (they were in components/places/from-bar.tsx, unchanged). */

export type SavedPlace = {
    id: number;
    name: string;
    category: string;
    address: string | null;
};

/** A geocoded address/place result for the From search. */
export type GeoResult = {
    name: string;
    address?: string | null;
    lat: number;
    lng: number;
};
