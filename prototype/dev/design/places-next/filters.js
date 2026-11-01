(function (root) {
    const empty = (query = '') => ({
        query,
        categories: [],
        areas: [],
        saved: false,
        sort: 'default',
    });
    function normalize(value, places) {
        const f = value && typeof value === 'object' ? value : {};
        const choices = (name, legacy, known) => [
            ...new Set(
                (Array.isArray(f[name])
                    ? f[name]
                    : typeof f[legacy] === 'string'
                      ? [f[legacy]]
                      : []
                ).filter((x) => known.includes(x)),
            ),
        ];
        return {
            query: typeof f.query === 'string' ? f.query : '',
            categories: choices(
                'categories',
                'category',
                places.map((x) => x.category),
            ).slice(0, 1),
            areas: choices(
                'areas',
                'area',
                places.map((x) => x.area),
            ),
            saved: f.saved === true,
            sort: f.sort === 'name' ? 'name' : 'default',
        };
    }
    function select(places, f, saved = []) {
        const q = (f.query || '').trim().toLocaleLowerCase();
        const result = places.filter(
            (p) =>
                (!f.categories.length || f.categories.includes(p.category)) &&
                (!f.areas.length || f.areas.includes(p.area)) &&
                (!f.saved ||
                    saved.some((x) => x.type === 'place' && x.id === p.id)) &&
                (!q ||
                    `${p.title} ${p.area} ${p.label} ${p.category}`
                        .toLocaleLowerCase()
                        .includes(q)),
        );
        if (f.sort === 'name')
            result.sort((a, b) => a.title.localeCompare(b.title));
        return result;
    }
    function withCategory(filters, category) {
        return { ...filters, categories: category === 'All' ? [] : [category] };
    }
    const api = { empty, normalize, select, withCategory };
    root.PlaceFilters = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
