/** "Köln Neumarkt" → "Neumarkt", as the stop signs say (the feed names carry the city). */
export function stopName(name: string, city?: string | null): string {
    return city && name.startsWith(`${city} `)
        ? name.slice(city.length + 1)
        : name;
}
