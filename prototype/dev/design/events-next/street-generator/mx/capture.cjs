// Capture the Events mock's own posters, sheet states, context and CSS.
const { chromium } = require('@playwright/test');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8792/dev/design/events-next/';
const out = process.argv[2];
const INHERITED = ['font-family','font-size','font-weight','font-style','font-stretch','line-height','letter-spacing','word-spacing','color','text-align','text-transform','text-indent','white-space','font-feature-settings','font-variation-settings','font-variant-numeric','font-kerning','font-optical-sizing','-webkit-font-smoothing','text-rendering','direction','cursor','hyphens','overflow-wrap','word-break','text-wrap','tab-size','color-scheme','accent-color','-webkit-tap-highlight-color','text-underline-offset','text-decoration-skip-ink','caret-color','font-synthesis','-webkit-text-size-adjust','text-size-adjust','list-style-type','quotes','visibility'];
(async () => {
  const b = await chromium.launch();
  const all = {};
  for (const vp of [{ name: 'desktop', width: 1440, height: 1000 }, { name: 'phone', width: 390, height: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, reducedMotion: 'reduce', hasTouch: vp.name === 'phone', isMobile: vp.name === 'phone' });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message));
    await page.goto(BASE, { waitUntil: 'networkidle' });
    await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
    const R = { posters: {}, chains: {}, context: {}, sheets: {}, dialogStyle: {}, toast: null, catalogue: null };
    const ctxOf = (sel) => page.evaluate(([sel, INH]) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const names = new Set();
      for (const s of document.styleSheets) {
        const walk = (rules) => { for (const r of rules) { if (r.style) for (const p of r.style) if (p.startsWith('--')) names.add(p); if (r.cssRules) walk(r.cssRules); if (r.styleSheet) walk(r.styleSheet.cssRules); } };
        try { walk(s.cssRules); } catch (e) {}
      }
      for (let e = el; e; e = e.parentElement) for (const p of e.style) if (p.startsWith('--')) names.add(p);
      const cs = getComputedStyle(el);
      const vars = {};
      for (const n of names) { const v = cs.getPropertyValue(n).trim(); if (v) vars[n] = v; }
      const inh = {};
      for (const p of INH) inh[p] = cs.getPropertyValue(p);
      return { vars, inh };
    }, [sel, INHERITED]);
    const chain = (sel) => page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const outp = [];
      for (let e = el.parentElement; e; e = e.parentElement) outp.unshift({ tag: e.tagName.toLowerCase(), attrs: [...e.attributes].map((a) => [a.name, a.value]) });
      return outp;
    }, sel);
    for (const date of ['', '2026-09-08']) {
      await page.goto(BASE + (date ? '?date=' + date : ''), { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);
      Object.assign(R.posters, await page.evaluate(() => {
        const o = {};
        document.querySelectorAll('#main .pp[data-poster]').forEach((el) => {
          const where = el.closest('.wall-column') ? 'column' : 'wall';
          o[el.dataset.poster + '|' + where] = el.outerHTML;
        });
        return o;
      }));
      if (!R.chains.wall) {
        R.chains.wall = await chain('.wall-day > .pp');
        R.chains.column = await chain('.wall-column-face .pp');
        R.context.wall = await ctxOf('.wall-day');
        R.context.column = await ctxOf('.wall-column-face');
        R.context.body = await ctxOf('body');
        R.sizes = await page.evaluate(() => ({ wall: [...document.querySelectorAll('.wall-day > .pp')].map((x) => [x.offsetWidth, x.offsetHeight, getComputedStyle(x).zoom]), column: [...document.querySelectorAll('.wall-column-face .pp')].map((x) => [x.offsetWidth, x.offsetHeight, getComputedStyle(x).zoom]) }));
      }
      // A turned poster, for the record.
      const first = page.locator('.wall-day > .pp .pp-open').first();
      if (await first.count()) {
        await first.click();
        await page.waitForTimeout(300);
        R.posters['_turned|' + (date || 'today')] = await page.evaluate(() => document.querySelector('.wall-day > .pp.is-turned')?.outerHTML || '');
        await page.locator('.wall-day > .pp.is-turned .pp-turn-back').first().click();
        await page.waitForTimeout(200);
      }
    }
    R.catalogue = await page.evaluate(() => ExpaduEventCatalogue());
    const snap = () => page.evaluate(() => document.querySelector('#shared-dialog')?.outerHTML || '');
    const dlg = (sel) => page.locator('#shared-dialog ' + sel);
    for (const it of R.catalogue) {
      const S = {};
      await page.evaluate((id) => ExpaduEventSheet.open(ExpaduEventCatalogue().find((x) => x.id === id)), it.id);
      await page.waitForTimeout(400);
      S.about = await snap();
      if (!R.dialogStyle.about) R.dialogStyle = await page.evaluate(() => {
        const d = document.querySelector('#shared-dialog');
        const pick = (cs, keys) => Object.fromEntries(keys.map((k) => [k, cs.getPropertyValue(k)]));
        const K = ['position','top','right','bottom','left','width','height','max-width','max-height','min-height','margin','padding','border','border-radius','overflow','transform','background-color','box-shadow','display','inset','z-index','box-sizing'];
        return { about: pick(getComputedStyle(d), K), rect: d.getBoundingClientRect().toJSON(), backdrop: pick(getComputedStyle(d, '::backdrop'), ['background-color','backdrop-filter','-webkit-backdrop-filter','opacity','transition']) };
      });
      // Save from the sheet's footer, then the toast.
      const save = dlg('.place-sheet-footer [data-save-item]').first();
      if (await save.count()) {
        await save.click();
        await page.waitForTimeout(250);
        S.aboutSaved = await snap();
        if (!R.toast) R.toast = await page.evaluate(() => ({ html: document.querySelector('#shared-toast').outerHTML }));
        await save.click();
        await page.waitForTimeout(250);
      }
      const planTab = dlg('.place-sheet-tabs [data-event-section="plan"]');
      if (await planTab.count()) {
        await planTab.click();
        await page.waitForTimeout(500);
        S.plan = await snap();
        await dlg('.selection-agenda summary').click();
        await page.waitForTimeout(400);
        S.planDay = await snap();
        await dlg('.visit-day-card').focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        S.planReveal = await snap();
        const del = dlg('[data-confirm-day-remove]').first();
        if (await del.count()) { await del.click(); await page.waitForTimeout(400); S.planRemoved = await snap(); }
        // Fresh, then add it.
        await page.evaluate(() => ExpaduUI.close());
        await page.waitForTimeout(300);
        await page.evaluate((id) => ExpaduEventSheet.open(ExpaduEventCatalogue().find((x) => x.id === id), 'plan'), it.id);
        await page.waitForTimeout(500);
        S.planViaButton = await snap();
        await dlg('[data-panel="plan"] button[type="submit"]').click();
        await page.waitForTimeout(600);
        S.planned = await snap();
        R.posterPlanned = R.posterPlanned || {};
        R.posterPlanned[it.id] = await page.evaluate((id) => document.querySelector(`#main .pp[data-poster="${id}"]`)?.getAttribute('data-stamp') || '', it.id);
        // Reopened later: kept, removable with a confirmation.
        await page.evaluate(() => ExpaduUI.close());
        await page.waitForTimeout(300);
        await page.evaluate((id) => ExpaduEventSheet.open(ExpaduEventCatalogue().find((x) => x.id === id), 'plan'), it.id);
        await page.waitForTimeout(500);
        S.plannedReopen = await snap();
        await dlg('[data-event-remove]').click();
        await page.waitForTimeout(400);
        S.removeConfirm = await snap();
        await dlg('[data-confirm-event-remove]').click();
        await page.waitForTimeout(500);
        S.removed = await snap();
        await page.evaluate(() => ExpaduUI.close());
        await page.waitForTimeout(300);
        await page.evaluate((id) => ExpaduEventSheet.open(ExpaduEventCatalogue().find((x) => x.id === id)), it.id);
        await page.waitForTimeout(400);
      }
      await dlg('.place-sheet-tabs [data-event-section="directions"]').click();
      await page.waitForTimeout(400);
      S.dir_transit = await snap();
      for (const m of ['walk', 'bike']) {
        await dlg(`input[name="mode"][value="${m}"]`).check({ force: true });
        await page.waitForTimeout(350);
        S['dir_' + m] = await snap();
      }
      await dlg('input[name="mode"][value="transit"]').check({ force: true });
      // Reopen on the plan tab the way a poster's Plan button does.
      await page.evaluate(() => ExpaduUI.close());
      await page.waitForTimeout(300);
      R.sheets[it.id] = S;
    }
    // The toast on screen.
    R.toastStyle = await page.evaluate(() => { const t = document.querySelector('#shared-toast'); const cs = getComputedStyle(t); return { position: cs.position, bottom: cs.bottom, left: cs.left, transform: cs.transform }; });
    // The stylesheets, as rule trees.
    R.css = await page.evaluate(() => {
      const ser = (rules, base) => [...rules].map((r) => {
        if (r instanceof CSSImportRule) return { t: 'import', href: r.href, media: r.media.mediaText, rules: r.styleSheet ? ser(r.styleSheet.cssRules, r.styleSheet.href) : [] };
        if (r instanceof CSSMediaRule) return { t: 'media', cond: r.media.mediaText, rules: ser(r.cssRules, base) };
        if (r instanceof CSSSupportsRule) return { t: 'supports', cond: r.conditionText, rules: ser(r.cssRules, base) };
        if (window.CSSContainerRule && r instanceof CSSContainerRule) return { t: 'container', head: r.cssText.slice(0, r.cssText.indexOf('{')).trim(), rules: ser(r.cssRules, base) };
        if (window.CSSLayerBlockRule && r instanceof CSSLayerBlockRule) return { t: 'layer', name: r.name, rules: ser(r.cssRules, base) };
        if (r instanceof CSSKeyframesRule) return { t: 'keyframes', name: r.name, text: r.cssText };
        if (r instanceof CSSFontFaceRule) return { t: 'font', text: r.cssText, base };
        if (r instanceof CSSStyleRule) return { t: 'style', sel: r.selectorText, decl: r.style.cssText, nested: r.cssRules && r.cssRules.length ? ser(r.cssRules, base) : undefined, base };
        return { t: 'other', text: r.cssText };
      });
      return [...document.styleSheets].map((s) => ({ href: s.href, rules: ser(s.cssRules, s.href) }));
    });
    R.errs = errs;
    all[vp.name] = R;
    await ctx.close();
  }
  fs.writeFileSync(out, JSON.stringify(all));
  console.log('posters', Object.keys(all.desktop.posters));
  console.log('sheets', Object.keys(all.desktop.sheets).map((k) => k + ':' + Object.keys(all.desktop.sheets[k]).join('/')));
  console.log('sizes', JSON.stringify(all.desktop.sizes), JSON.stringify(all.phone.sizes));
  console.log('dialog desk', JSON.stringify(all.desktop.dialogStyle));
  console.log('dialog phone', JSON.stringify(all.phone.dialogStyle));
  console.log('errs', all.desktop.errs, all.phone.errs);
  await b.close();
})();
