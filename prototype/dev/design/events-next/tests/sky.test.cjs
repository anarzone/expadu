const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {};
context.globalThis = context;
for (const file of ['../sky.js', '../sample-weather.js'])
    vm.runInNewContext(fs.readFileSync(require.resolve(file), 'utf8'), context);
const K = context.ExpaduSky;
const W = context.ExpaduSampleWeather;
const cologne = {
    lat: 50.9384,
    lng: 6.9599,
    timeZone: 'Europe/Berlin',
};
const near = (actual, expected, minutes, label) =>
    assert.ok(
        Math.abs(actual - expected) * 60 <= minutes,
        `${label}: ${K.clock(actual)}, expected about ${K.clock(expected)}`,
    );

test('sunrise and sunset in Cologne follow the date and the clock change', () => {
    const autumn = K.sun('2026-09-30', cologne);
    near(autumn.rise, K.hours('07:28'), 4, 'sunrise 30 Sept');
    near(autumn.set, K.hours('19:14'), 4, 'sunset 30 Sept');
    // Clocks go back on 25 October: sunset jumps an hour earlier.
    const after = K.sun('2026-10-26', cologne);
    assert.equal(autumn.offset, 120);
    assert.equal(after.offset, 60);
    near(after.set, K.hours('17:20'), 4, 'sunset 26 Oct');
    const midsummer = K.sun('2026-06-21', cologne);
    near(midsummer.set, K.hours('21:51'), 5, 'sunset 21 June');
    assert.ok(K.sunHeight(midsummer, midsummer.noon, cologne) > 60);
});

test('the moon keeps its real phase', () => {
    // Full on 26 September 2026, new on 11 September. The phase follows
    // the average month, so it is right to within about a day.
    const full = K.moonPhase('2026-09-26', 18.8, 120);
    assert.ok(Math.abs(full - 0.5) < 0.035, `full moon phase ${full}`);
    const fresh = K.moonPhase('2026-09-11', 5, 120);
    assert.ok(fresh < 0.035 || fresh > 0.965, `new moon phase ${fresh}`);
    // Waning after full: the left side is lit, so the path starts by
    // sweeping round the left.
    assert.match(K.moonPath(0.64, 20), /^M0 -20A20 20 0 0 0 0 20/);
    assert.match(K.moonPath(0.3, 20), /^M0 -20A20 20 0 0 1 0 20/);
});

test('the sky is light by day, dark at night, and greys under cloud', () => {
    const ctx = { date: '2026-09-30', city: cologne, weather: null };
    const noon = K.at(ctx, 13);
    const night = K.at(ctx, 23);
    assert.equal(noon.light, true);
    assert.equal(night.light, false);
    assert.ok(noon.sun.opacity > 0.9 && night.sun.opacity === 0);
    assert.ok(night.stars > 0.9 && noon.stars === 0);
    assert.ok(night.windows > noon.windows);
    // The sun sinks behind the city as it sets.
    assert.ok(K.at(ctx, 19.3).sun.y > 380);
    const grey = K.at({ ...ctx, weather: Array(24).fill(['cloudy', 15, 20]) }, 13);
    const spread = (hex) => {
        const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
        return Math.max(...c) - Math.min(...c);
    };
    assert.ok(spread(grey.colours[0]) < spread(noon.colours[0]) / 2);
});

test('the weather is read hour by hour and blends into the next', () => {
    const day = W.days['2026-09-30'];
    const lunch = K.weatherAt(day, 13.17);
    assert.deepEqual([lunch.sky, lunch.temp, lunch.cover, lunch.rain], ['clear', 21, 0, 0]);
    const evening = K.weatherAt(day, 20);
    assert.equal(evening.sky, 'rain');
    assert.ok(evening.rain > 0.9);
    const between = K.weatherAt(day, 17.5);
    assert.ok(between.rain > 0 && between.rain < 0.6);
    assert.equal(K.weatherAt(null, 12), null);
    // Rain is "likely" from a 50% chance at the event's hour.
    assert.equal(K.rainLikely(day, '20:00'), true);
    assert.equal(K.rainLikely(day, '13:00'), false);
    assert.equal(K.rainLikely(null, '20:00'), false);
});

test('the chip says the hour, the weather and where it is from', () => {
    const city = cologne;
    const ctx = {
        date: '2026-09-30',
        city,
        weather: W.days['2026-09-30'],
        source: 'Sample weather',
    };
    assert.match(K.chip(K.at(ctx, K.hours('13:10')), ctx), /21°<\/strong> Sunny<\/span><small>13:10 · Sample weather/);
    assert.match(K.chip(K.at(ctx, 20), ctx), /15°<\/strong> Rain/);
    const past = { date: '2026-09-08', city, weather: null, past: true };
    assert.match(K.chip(K.at(past, 10), past), /10:00.*Weather not recorded/);
    const later = { ...past, past: false };
    assert.match(K.chip(K.at(later, 10), later), /No forecast yet/);
});
