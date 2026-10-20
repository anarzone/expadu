/**
 * The cities Expadu covers. Cologne for now; another city is a new entry
 * here (and its data), not a design change.
 */
export type City = {
    id: string;
    name: string;
    country: string;
    aliases: string[];
};

export const CITIES: City[] = [
    {
        id: 'cologne',
        name: 'Cologne',
        country: 'Germany',
        aliases: ['Köln', 'Koeln'],
    },
];

export const CURRENT_CITY = CITIES[0];

const normalize = (value: string) =>
    value.toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

/** Cities whose name, country or alias holds the query. */
export function citiesMatching(query: string): City[] {
    const q = normalize(query);

    return q
        ? CITIES.filter((city) =>
              [city.name, city.country, ...city.aliases].some((value) =>
                  normalize(value).includes(q),
              ),
          )
        : [];
}
