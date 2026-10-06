const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const ts = require('typescript');
const exports_ = {};
const file = path.resolve(
    __dirname,
    '../../../resources/js/components/services/service-card.tsx',
);
const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        jsx: ts.JsxEmit.ReactJSX,
    },
}).outputText;
vm.runInNewContext(code, {
    exports: exports_,
    require: (id) =>
        id === '@/constants/icons' ? { ICON_STROKE: 1.7 } : require(id),
});
const service = {
    id: 1,
    cat: 'tax',
    name: 'Example advisor',
    type: 'Tax advisor',
    emoji: '📋',
    avatarBg: '#fff',
    rating: 0,
    reviews: 0,
    verified: false,
    languages: [],
    address: '',
    distance: '',
    phone: null,
    acceptingNew: false,
    insurance: [],
    desc: '',
    hours: '',
    website: '',
    tips: [],
};

test('a provider without positive availability does not invent a waitlist or reviews', () => {
    const html = renderToStaticMarkup(
        React.createElement(exports_.ServiceCard, { service, onClick() {} }),
    );
    assert.doesNotMatch(html, /Waitlist|Accepting patients|0 reviews/);
    assert.match(html, /Availability to confirm/);
});
