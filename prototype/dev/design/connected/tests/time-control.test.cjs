const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const timeContext = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(path.resolve(__dirname, '../time-control.js'), 'utf8'),
    timeContext,
);
const time = timeContext.module.exports;
const context = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(
        path.resolve(__dirname, '../../composer-studio/model.js'),
        'utf8',
    ),
    context,
);
const composer = context.module.exports;
test('shared time entry retains Composer normalization and validation', () => {
    for (const value of [
        '00:00',
        '23:59',
        '9:05',
        '905',
        '1300',
        ' 14:32 ',
        '24:00',
        '13:60',
        'oops',
        '',
    ]) {
        assert.equal(
            time.normalizeTime(value),
            composer.normalizeTime(value),
            value,
        );
    }
});
test('shared wheels retain all 24 hours and all 60 minutes', () => {
    const values = time.timeWheelValues();
    assert.equal(
        JSON.stringify(values),
        JSON.stringify(composer.timeWheelValues()),
    );
    assert.equal(values.hours.length, 24);
    assert.equal(values.minutes.length, 60);
});
test('Places and Composer load the same wheel implementation and stylesheet', () => {
    const root = path.resolve(__dirname, '../..');
    for (const page of ['places-next', 'composer-studio']) {
        const html = fs.readFileSync(
            path.join(root, page, 'index.html'),
            'utf8',
        );
        assert.match(html, /connected\/time-control\.js\?v=/);
        assert.match(html, /connected\/time-control\.css\?v=/);
        assert.match(html, /wheel-motion\.js\?v=/);
    }
    const app = fs.readFileSync(
        path.join(root, 'composer-studio/app.js'),
        'utf8',
    );
    assert.doesNotMatch(app, /function openTimeOptions/);
    assert.match(app, /ExpaduTime.close\(\)/);
});
