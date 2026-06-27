/**
 * v4 category tint helper — maps a place/event category to its `.cat-*` class.
 * The class sets `--cat-tint` (image wash) and `--cat-mark` (icon ink), both
 * defined for light and dark in app.css. Apply it to a card/tile and read the
 * vars in inline styles. Unknown categories return '' (no tint).
 */
const CATEGORY_CLASSES: Record<string, string> = {
    park: 'cat-park',
    culture: 'cat-culture',
    language: 'cat-language',
    pitch: 'cat-pitch',
    court: 'cat-court',
    sport: 'cat-sport',
    sports: 'cat-sports',
    swimming: 'cat-swimming',
    music: 'cat-music',
    meetup: 'cat-meetup',
    playground: 'cat-playground',
    stammtisch: 'cat-stammtisch',
    cafe: 'cat-cafe',
    dog_park: 'cat-dog_park',
    event: 'cat-event',
    party: 'cat-party',
};

function categoryClass(category?: string | null): string {
    if (!category) {
        return '';
    }

    return CATEGORY_CLASSES[category.toLowerCase()] ?? '';
}

export { categoryClass, CATEGORY_CLASSES };
