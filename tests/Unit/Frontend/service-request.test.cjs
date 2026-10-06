const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const exports_ = {};
const file = path.resolve(
    __dirname,
    '../../../resources/js/components/services/service-request.ts',
);
const source = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
vm.runInNewContext(
    ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports: exports_ },
);

test('a service request retains the chosen preferences without claiming a provider or booking', () => {
    assert.equal(typeof exports_.serviceRequestPrompt, 'function');
    const prompt = exports_.serviceRequestPrompt({
        service: 'dentist',
        area: '  Deutz ',
        language: ' English ',
        insurance: ' Public ',
        notes: ' Evening appointments if available. ',
    });
    assert.match(prompt, /dentist/);
    assert.match(prompt, /Area: Deutz\./);
    assert.match(prompt, /Language: English\./);
    assert.match(prompt, /Insurance preference: Public\./);
    assert.match(prompt, /Evening appointments if available\./);
    assert.doesNotMatch(
        prompt,
        /booked|confirmed|available provider|verified provider/,
    );
});

test('skipped service preferences remain absent from the Composer request', () => {
    assert.equal(typeof exports_.serviceRequestPrompt, 'function');
    const prompt = exports_.serviceRequestPrompt({
        service: 'local service',
        area: '',
        language: ' ',
        insurance: '',
        notes: '',
    });
    assert.doesNotMatch(prompt, /Area:|Language:|Insurance preference:/);
    assert.match(prompt, /local service/);
    assert.ok(prompt.length <= 500);
});

test('a copied provider message is directly usable and does not include Composer instructions', () => {
    assert.equal(typeof exports_.serviceRequestMessage, 'function');
    const message = exports_.serviceRequestMessage({
        service: 'dentist',
        area: ' Deutz ',
        language: ' English ',
        insurance: 'Public',
        notes: 'Evenings preferred.',
    });
    assert.match(message, /^Hello,/);
    assert.match(message, /dentist in Deutz/);
    assert.match(message, /English/);
    assert.match(message, /Public/);
    assert.match(message, /Evenings preferred\./);
    assert.doesNotMatch(message, /Help me prepare|Composer|booked|confirmed/);
});
