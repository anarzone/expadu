const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const context = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../wheel-motion.js'), 'utf8'),
    context,
);
const { create, landing } = context.module.exports;
function fixture(position = 440, reduced = false) {
    let time = 0,
        id = 0;
    const jobs = new Map(),
        history = [];
    const motion = create({
        read: () => position,
        write: (value) => {
            position = value;
            history.push(value);
        },
        max: 23 * 44,
        reduced: () => reduced,
        now: () => time,
        request: (callback) => {
            jobs.set(++id, callback);
            return id;
        },
        cancel: (id) => jobs.delete(id),
    });
    return {
        motion,
        history,
        position: () => position,
        jobs,
        step(ms) {
            time += ms;
            const callbacks = [...jobs.values()];
            jobs.clear();
            callbacks.forEach((callback) => callback(time));
        },
    };
}
test('a flick carries in its direction but stays bounded to four rows and valid hours', () => {
    assert.equal(landing(440, 1, 1012), 572);
    assert.equal(landing(440, 20, 1012), 616);
    assert.equal(landing(440, -20, 1012), 264);
    assert.equal(landing(10, -1, 1012), 0);
    assert.equal(landing(1000, 1, 1012), 1012);
});
test('settling glides monotonically without overshoot then stops exactly on a row', () => {
    const f = fixture(457);
    f.motion.release();
    assert.equal(f.position(), 457);
    for (let i = 0; i < 30; i++) f.step(16);
    assert.equal(f.position(), 440);
    assert.equal(f.motion.active(), false);
    assert.equal(f.jobs.size, 0);
    assert.ok(
        f.history.every(
            (value, index) =>
                value >= 440 && (index === 0 || value <= f.history[index - 1]),
        ),
    );
});
test('a new gesture cancels the glide and stale frames cannot move the wheel', () => {
    const f = fixture();
    f.motion.release(1);
    const stale = [...f.jobs.values()][0];
    f.step(32);
    f.motion.stop();
    const stopped = f.position();
    stale(1000);
    f.step(1000);
    assert.equal(f.position(), stopped);
    assert.equal(f.jobs.size, 0);
});
test('closing during motion settles once and cancels future frames', () => {
    const f = fixture();
    f.motion.release(1);
    f.step(80);
    f.motion.finish();
    assert.equal(f.position() % 44, 0);
    const final = f.position();
    f.step(1000);
    assert.equal(f.position(), final);
    assert.equal(f.motion.active(), false);
});
test('reduced motion disables inertia and settles immediately without animation frames', () => {
    const f = fixture(450, true);
    f.motion.release(2);
    assert.equal(f.position(), 440);
    assert.equal(f.jobs.size, 0);
});
