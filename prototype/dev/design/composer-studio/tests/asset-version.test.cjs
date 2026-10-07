const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
for (const [page, asset] of [
    ['today-c', '../connected/store.js'],
    ['today-c', '../connected/bridge.js'],
    ['today-c', './slides.js'],
    ['today-c', './day-brief-model.js'],
    ['today-c', './overview-sync.js'],
    ['today-c', './composer-controls.js'],
    ['composer-studio', '../connected/store.js'],
    ['composer-studio', './app.js'],
    ['places-next', '../connected/store.js'],
    ['events-next', '../connected/store.js'],
    ['services-next', '../connected/store.js'],
    ['bureaucracy-next', '../connected/store.js'],
    ['departures-next', '../connected/store.js'],
]) {
    test(`${page} invalidates cached ${asset} when its contents change`, () => {
        const html = fs.readFileSync(
            path.join(root, page, 'index.html'),
            'utf8',
        );
        const url = [...html.matchAll(/src="([^"]+)"/g)]
            .map((x) => x[1])
            .find((x) => x.split('?')[0] === asset);
        assert.ok(url, `Missing ${asset}`);
        const digest = crypto
            .createHash('sha256')
            .update(fs.readFileSync(path.resolve(root, page, asset)))
            .digest('hex')
            .slice(0, 12);
        assert.equal(
            new URL(url, 'http://localhost/').searchParams.get('v'),
            digest,
            'Browser must request the updated script, rather than reuse the old route',
        );
    });
}
