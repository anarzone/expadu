const { test } = require('node:test'),
    assert = require('node:assert/strict'),
    fs = require('node:fs'),
    path = require('node:path'),
    vm = require('node:vm'),
    crypto = require('node:crypto');
const dir = path.join(__dirname, '..');
test('entry page versions every local asset by its content to avoid old/new preview mixtures', () => {
    const html = fs.readFileSync(path.join(dir, 'index.html'), 'utf8');
    for (const file of [
        'style.css',
        'source-data.js',
        'model.js',
        'plan.js',
        'app.js',
        'orientation.js',
        'orientation-ui.js',
        'orientation.css',
        'details-review.js',
        'details-ui.js',
        'family.js',
        'family-ui.js',
        'situation.js',
        'situation-ui.js',
        'situation.css',
    ]) {
        const version = crypto
            .createHash('sha256')
            .update(fs.readFileSync(path.join(dir, file)))
            .digest('hex')
            .slice(0, 12);
        assert.ok(
            html.includes('./' + file + '?v=' + version),
            file + ' must have a matching cache version',
        );
    }
});
test('every visible process and action points to a present source fixture and scoped document list', () => {
    const ctx = vm.createContext({ window: {} });
    for (const file of ['source-data.js', 'model.js', 'plan.js'])
        vm.runInContext(fs.readFileSync(path.join(dir, file), 'utf8'), ctx);
    const records = ctx.window.BureaucracySource,
        s = ctx.BureaucracyPlan.hydrate(ctx.BureaucracyPreview.fresh()),
        plan = ctx.BureaucracyPlan.projection(s);
    for (const a of plan.actions) assert.ok(records[a.record]?.title);
    for (const p of plan.processes)
        assert.equal(
            p.docs.length,
            p.records.flatMap((i) => records[i].documents).length,
            p.name,
        );
    for (const h of plan.history) assert.ok(records[h.record]?.title);
});

test('shared time and planning controls load their dependencies before use', () => {
    const html = fs.readFileSync(path.join(dir, 'index.html'), 'utf8');
    const scripts = [...html.matchAll(/src="([^"?]+)/g)].map(
        (match) => match[1],
    );
    const before = (dependency, consumer) => {
        assert.ok(scripts.includes(dependency), dependency);
        assert.ok(
            scripts.indexOf(dependency) < scripts.indexOf(consumer),
            `${dependency} must load before ${consumer}`,
        );
    };
    before(
        '../composer-studio/wheel-motion.js',
        '../connected/time-control.js',
    );
    before('../composer-studio/model.js', './planning-model.js');
    before('../composer-studio/conversation.js', './planning-model.js');
    before('./planning-model.js', './planning-ui.js');
    before('./planning-ui.js', './app.js');
    before('./details-review.js', './details-ui.js');
    before('./details-ui.js', './orientation-ui.js');
    before('./family.js', './orientation.js');
    before('./family-ui.js', './app.js');
    before('./orientation.js', './situation.js');
    before('./situation.js', './situation-ui.js');
    before('./situation-ui.js', './orientation-ui.js');
    before('./orientation.js', './orientation-ui.js');
    before('./plan.js', './orientation-ui.js');
    before('./orientation-ui.js', './app.js');
});
