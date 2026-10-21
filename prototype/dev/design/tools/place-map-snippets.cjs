/* Render a small map picture for each sample place, used on place cards
   instead of a photo. Uses the vendored MapLibre with OpenFreeMap's Positron
   style, as Travel's map does (map data © OpenStreetMap contributors). The
   real app would render these from its self-hosted tiles.

   Needs the prototype server on :8765 and network access for the tiles.
   Images are written to connected/place-maps/ (not committed, like the other
   prototype images); cards fall back to the place's icon without them.

     NODE_PATH=$PWD/node_modules node prototype/dev/design/tools/place-map-snippets.cjs */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const design = path.resolve(__dirname, '..');
const out = path.join(design, 'connected/place-maps');
const base = 'http://127.0.0.1:8765/dev/design/';
const context = {};
new Function(
    'globalThis',
    fs.readFileSync(path.join(design, 'connected/sample-places.js'), 'utf8'),
)(context);
const places = context.ExpaduSamplePlaces.places;

(async () => {
    fs.mkdirSync(out, { recursive: true });
    const browser = await chromium.launch();
    const page = await browser.newPage({
        viewport: { width: 400, height: 260 },
        deviceScaleFactor: 2,
    });
    await page.goto(`${base}connected/`, { waitUntil: 'domcontentloaded' });
    await page.setContent(`<!doctype html><html><head>
        <link rel="stylesheet" href="${base}places-optional/vendor/maplibre-gl.css">
        <script src="${base}places-optional/vendor/maplibre-gl.js"></script>
        <style>html,body{margin:0}#map{width:320px;height:180px}.maplibregl-ctrl-attrib,.maplibregl-ctrl-logo{display:none!important}</style>
        </head><body><div id="map"></div></body></html>`);
    await page.waitForFunction(() => window.maplibregl);
    await page.evaluate(() => {
        window.map = new maplibregl.Map({
            container: 'map',
            style: 'https://tiles.openfreemap.org/styles/positron',
            center: [6.95, 50.94],
            zoom: 15,
            interactive: false,
            attributionControl: false,
            preserveDrawingBuffer: true,
            fadeDuration: 0,
        });
        return new Promise((resolve) => map.on('load', resolve));
    });
    let made = 0;
    for (const place of places) {
        const file = path.join(out, `${place.id}.png`);
        await page.evaluate(
            ({ lat, lng, zoom }) =>
                new Promise((resolve) => {
                    const point = {
                        type: 'Feature',
                        geometry: { type: 'Point', coordinates: [lng, lat] },
                    };
                    if (map.getSource('place'))
                        map.getSource('place').setData(point);
                    else {
                        map.addSource('place', {
                            type: 'geojson',
                            data: point,
                        });
                        map.addLayer({
                            id: 'place-ring',
                            type: 'circle',
                            source: 'place',
                            paint: {
                                'circle-radius': 9,
                                'circle-color': '#ffffff',
                            },
                        });
                        map.addLayer({
                            id: 'place-dot',
                            type: 'circle',
                            source: 'place',
                            paint: {
                                'circle-radius': 6,
                                'circle-color': '#00768b',
                            },
                        });
                    }
                    map.jumpTo({ center: [lng, lat], zoom });
                    map.once('idle', resolve);
                }),
            {
                lat: place.lat,
                lng: place.lng,
                zoom: place.point === 'area' ? 15 : 16,
            },
        );
        await page.locator('#map').screenshot({ path: file });
        made += 1;
    }
    await browser.close();
    console.log(`${made} map pictures → ${path.relative(design, out)}`);
})();
