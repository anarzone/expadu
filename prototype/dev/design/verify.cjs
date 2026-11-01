// Bounded interaction smoke check. Does not substitute for browser usability QA.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, 'app.js'), 'utf8');
const assets = ['riverside.png', 'gathering.png', 'fonts/geist-400.woff2', 'fonts/fraunces-500.woff2'];
for (const asset of assets) assert.ok(fs.statSync(path.join(__dirname, 'assets', asset)).size > 0, asset);

for (const concept of ['fresh', 'refined']) {
  const elements = new Map();
  const listeners = {};
  function element(selector) {
    if (!elements.has(selector)) elements.set(selector, {
      innerHTML: '', textContent: '', open: false, dataset: {},
      classList: { add() {}, remove() {}, toggle() {} },
      setAttribute() {}, addEventListener() {}, focus() {}, scrollIntoView() {},
      showModal() { this.open = true; }, close() { this.open = false; },
    });
    return elements.get(selector);
  }
  const context = vm.createContext({
    URLSearchParams, location: { search: `?concept=${concept}` },
    setTimeout: () => 0, clearTimeout() {},
    window: { scrollTo() {} },
    document: {
      body: element('body'), querySelector: element, querySelectorAll: () => [],
      addEventListener(type, callback) { listeners[type] = callback; },
    },
  });
  vm.runInContext(source, context);
  const run = (code) => vm.runInContext(code, context);
  const click = (dataset) => listeners.click({ target: { closest: () => ({ dataset }) } });
  assert.match(elements.get('#app').innerHTML, concept === 'fresh' ? /top-nav/ : /refined-shell/);
  assert.doesNotMatch(elements.get('#app').innerHTML, /today-fresh\.png|today-refined\.png/);
  click({ add: 'green' }); click({ add: 'night' });
  click({ nav: 'planner' });
  assert.match(elements.get('#dialog-body').innerHTML, /Grünzug Seeberg/);
  assert.match(elements.get('#dialog-body').innerHTML, /Newcomers’ Night/);
  click({ remove: 'green' });
  assert.doesNotMatch(elements.get('#dialog-body').innerHTML, /Grünzug Seeberg/);
  click({ action: 'save-day' });
  assert.equal(run('state.daySaved'), true);
  assert.equal(run('JSON.stringify(state.plan)'), '["night"]');
  click({ add: 'table' });
  assert.equal(run('state.daySaved'), false, 'Editing a saved selection marks it unsaved');
  click({ save: 'green' });
  assert.equal(run('state.saved.has("green")'), true);
  assert.equal(run('state.plan.includes("green")'), false, 'Bookmarking does not silently add to the day');
  click({ filter: 'culture' });
  assert.match(elements.get('#featured-places').innerHTML, /Newcomers’ Night/);
  assert.doesNotMatch(elements.get('#featured-places').innerHTML, /Grünzug Seeberg/);
  run('showCatalogue("places", "<img src=x onerror=alert(1)>")');
  assert.doesNotMatch(elements.get('#dialog-body').innerHTML, /value="<img/);
  assert.match(elements.get('#dialog-body').innerHTML, /&lt;img/);
  console.log(`${concept}: render, details, selection, removal, exact save, bookmark separation, filtering and escaped search passed`);
}
