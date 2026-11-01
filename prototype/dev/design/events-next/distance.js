/* Straight-line distances for the Events preview: not a route or a travel
   time. District centres and venue points were read from Expadu's local
   catalogue on 7 October 2026 (veedels, venues and spots tables); Neumarkt is
   Travel's captured stop. A starting point is recognised only when it names one
   of these places; otherwise no distance is shown. */
(function (root) {
    // [name, latitude, longitude]
    const places = [
        ['Neumarkt', 50.93576, 6.94811],
        ['Altstadt-Nord', 50.94226, 6.95319],
        ['Altstadt-Süd', 50.92918, 6.95533],
        ['Bayenthal', 50.91061, 6.96997],
        ['Bickendorf', 50.96047, 6.89764],
        ['Bilderstöckchen', 50.97187, 6.92932],
        ['Blumenberg', 51.04008, 6.87701],
        ['Bocklemünd/Mengenich', 50.9762, 6.86116],
        ['Braunsfeld', 50.94013, 6.89738],
        ['Brück', 50.94446, 7.0845],
        ['Buchforst', 50.95138, 7.00514],
        ['Buchheim', 50.96123, 7.02523],
        ['Chorweiler', 51.02748, 6.8979],
        ['Dellbrück', 50.97377, 7.07781],
        ['Deutz', 50.93589, 6.9792],
        ['Dünnwald', 51.00379, 7.04363],
        ['Ehrenfeld', 50.95022, 6.91254],
        ['Eil', 50.90116, 7.10113],
        ['Elsdorf', 50.87087, 7.08363],
        ['Ensen', 50.89925, 7.03638],
        ['Esch/Auweiler', 51.00729, 6.85608],
        ['Finkenberg', 50.89764, 7.06304],
        ['Flittard', 51.0074, 6.99029],
        ['Fühlingen', 51.0353, 6.9059],
        ['Godorf', 50.8528, 6.98126],
        ['Gremberghoven', 50.90777, 7.04837],
        ['Grengel', 50.86977, 7.13896],
        ['Hahnwald', 50.87335, 6.98351],
        ['Heimersdorf', 51.00927, 6.9015],
        ['Höhenberg', 50.94379, 7.02951],
        ['Höhenhaus', 50.98604, 7.03181],
        ['Holweide', 50.96561, 7.04715],
        ['Humboldt/Gremberg', 50.9256, 7.00768],
        ['Immendorf', 50.85122, 6.95526],
        ['Junkersdorf', 50.92419, 6.85994],
        ['Kalk', 50.93849, 7.00379],
        ['Klettenberg', 50.9045, 6.92141],
        ['Langel', 50.84919, 7.01527],
        ['Libur', 50.8481, 7.07328],
        ['Lind', 50.84737, 7.10297],
        ['Lindenthal', 50.92706, 6.90438],
        ['Lindweiler', 51.0045, 6.88798],
        ['Longerich', 50.99902, 6.91645],
        ['Lövenich', 50.94928, 6.83137],
        ['Marienburg', 50.89639, 6.97585],
        ['Mauenheim', 50.97337, 6.9446],
        ['Merheim', 50.94707, 7.04838],
        ['Merkenich', 51.03965, 6.93011],
        ['Meschenich', 50.85412, 6.93117],
        ['Mülheim', 50.96656, 7.00893],
        ['Müngersdorf', 50.94277, 6.87066],
        ['Neubrück', 50.93545, 7.06236],
        ['Neuehrenfeld', 50.95972, 6.92303],
        ['Neustadt-Nord', 50.94791, 6.94107],
        ['Neustadt-Süd', 50.92656, 6.94041],
        ['Niehl', 50.99409, 6.95522],
        ['Nippes', 50.96377, 6.95533],
        ['Ossendorf', 50.9792, 6.89256],
        ['Ostheim', 50.92809, 7.04283],
        ['Pesch', 50.99905, 6.87595],
        ['Poll', 50.91403, 6.9942],
        ['Porz', 50.88256, 7.06248],
        ['Raderberg', 50.91089, 6.95873],
        ['Raderthal', 50.89441, 6.9552],
        ['Rath/Heumar', 50.92847, 7.09978],
        ['Riehl', 50.96283, 6.97939],
        ['Rodenkirchen', 50.88445, 6.99737],
        ['Roggendorf/Thenhoven', 51.04766, 6.83463],
        ['Rondorf', 50.87839, 6.94119],
        ['Seeberg', 51.01733, 6.9102],
        ['Stammheim', 50.98734, 6.98546],
        ['Sülz', 50.91333, 6.90856],
        ['Sürth', 50.86226, 7.00729],
        ['Urbach', 50.8815, 7.08404],
        ['Vingst', 50.93066, 7.02295],
        ['Vogelsang', 50.9617, 6.87768],
        ['Volkhoven/Weiler', 51.02012, 6.87616],
        ['Wahn', 50.85769, 7.0876],
        ['Wahnheide', 50.86221, 7.10782],
        ['Weiden', 50.93366, 6.83342],
        ['Weidenpesch', 50.98175, 6.94338],
        ['Weiß', 50.88148, 7.0372],
        ['Westhoven', 50.90454, 7.01337],
        ['Widdersdorf', 50.96613, 6.83855],
        ['Worringen', 51.06373, 6.86152],
        ['Zollstock', 50.90454, 6.93657],
        ['Zündorf', 50.8638, 7.04613],
    ];
    const fold = (text) =>
        String(text || '')
            .toLocaleLowerCase('de')
            .replace(/ß/g, 'ss')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\b(koln|cologne)\b/g, ' ')
            .replace(/[^a-z0-9/]+/g, ' ')
            .trim();
    // The place named in a starting point, e.g. "Neumarkt", "Köln-Ehrenfeld"
    // or "Venloer Str., Ehrenfeld". The longest matching name wins.
    function locate(text, extra = []) {
        const query = ` ${fold(text)} `;
        if (!query.trim()) return null;
        const known = [
            ...places.map(([name, lat, lng]) => ({ name, lat, lng })),
            ...extra.filter((x) => x && Number.isFinite(x.lat)),
        ];
        return (
            known
                .map((place) => ({ place, key: fold(place.name) }))
                .filter(({ key }) => key && query.includes(` ${key} `))
                .sort((a, b) => b.key.length - a.key.length)[0]?.place || null
        );
    }
    function km(a, b) {
        if (![a?.lat, a?.lng, b?.lat, b?.lng].every(Number.isFinite))
            return null;
        const rad = (x) => (x * Math.PI) / 180;
        const dLat = rad(b.lat - a.lat),
            dLng = rad(b.lng - a.lng);
        const h =
            Math.sin(dLat / 2) ** 2 +
            Math.cos(rad(a.lat)) *
                Math.cos(rad(b.lat)) *
                Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.asin(Math.sqrt(h));
    }
    const format = (value) =>
        value === null || !Number.isFinite(value)
            ? ''
            : value < 1
              ? `${Math.max(100, Math.round(value * 10) * 100)} m`
              : `${value < 10 ? value.toFixed(1) : Math.round(value)} km`;
    root.ExpaduEventDistance = { locate, km, format, places };
})(globalThis);
