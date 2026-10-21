const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const scope = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../navigation.js'), 'utf8'),
    scope,
);
const { panelUrl } = scope.module.exports;

test('closing a file preview panel changes only its fragment', () => {
    const document =
        'file:///Users/example/My%20Project/composer-studio/index.html';
    for (const panel of ['replace', 'saved', 'plans']) {
        const opened = panelUrl(document, panel);
        assert.equal(opened, `${document}#${panel}`);
        assert.equal(panelUrl(opened), document);
        assert.equal(
            new URL(opened).pathname,
            new URL(panelUrl(opened)).pathname,
        );
    }
});
test('panel navigation preserves served paths and query parameters', () => {
    const document =
        'http://127.0.0.1:8765/dev/design/composer-studio/?compose=1';
    assert.equal(
        panelUrl(`${document}#saved`, 'replace'),
        `${document}#replace`,
    );
    assert.equal(panelUrl(`${document}#replace`), document);
});
test('legacy My Plans preview keeps its own document address', () => {
    const document =
        'file:///Users/example/prototype/dev/design/my-plans/index.html';
    assert.equal(panelUrl(`${document}#plans`), document);
});
