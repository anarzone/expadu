const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const context = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../scroll-header.js'), 'utf8'),
    context,
);
const { update } = context.module.exports;
const initial = {
    y: 0,
    direction: 0,
    travel: 0,
    floating: false,
    visible: true,
};
test('header scrolls away naturally, then returns after a deliberate upward movement', () => {
    let s = update(initial, 100, 30, 150);
    assert.equal(s.floating, false);
    s = update(s, 240, 30, 150);
    assert.equal(s.floating, true);
    assert.equal(s.visible, false);
    s = update(s, 235, 30, 150);
    assert.equal(s.visible, false);
    s = update(s, 224, 30, 150);
    assert.equal(s.visible, true);
    s = update(s, 228, 30, 150);
    assert.equal(s.visible, true);
    s = update(s, 240, 30, 150);
    assert.equal(s.visible, false);
});
test('returning to the top restores the original header in normal flow', () => {
    const floating = update(initial, 300, 30, 150);
    const top = update(floating, 0, 30, 150);
    assert.equal(top.floating, false);
    assert.equal(top.visible, true);
});
test('focused navigation stays visible while scrolling', () => {
    const floating = update(initial, 300, 30, 150);
    const focused = update(floating, 360, 30, 150, true);
    assert.equal(focused.visible, true);
});
