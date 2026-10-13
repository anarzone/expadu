#!/usr/bin/env python3
"""Four app-like ways to browse a 100-event day, all on the living street:
1 Shelves (Spotify/Netflix), 2 Stories (Instagram), 3 Swipe (Tinder/Hinge),
4 Scroll the day (the page is the wall; the sky follows the scroll).
Usage: gen_apps.py [assets.json]"""
import json, os, sys
import scene_art as A
import gen_scene as GS
import stories_ui as SU
import stories_real as SR
import mx as MX
from gen_wall2 import LEFT, RIGHT, ARROW_BTN

H = A.H
AMAP = json.load(open(sys.argv[1])) if len(sys.argv) > 1 else {}
GS.AMAP = AMAP
AS = lambda f: AMAP.get(f, 'assets/' + f)
PROJ = GS.PROJ
CATS = {'Culture': '#4a2bc0', 'Games': '#211d15', 'Outdoors': '#2f8a4a', 'Language': '#8a73d6'}

EXTRA = r'''
  catOf(e) {
    const STY = __STYLES__;
    const map = { 'day-rings': 'Culture', 'evening-waves': 'Outdoors', 'day-checks': 'Games', 'evening-bubbles': 'Language', 'evening-rings': 'Culture' };
    return e.cat || map[STY[e.n % STY.length]];
  }
  skyBg(h, dir) {
    const k = this.sky(h);
    return 'linear-gradient(' + (dir || '180deg') + ',' + k.s1 + ',' + k.s2 + ' 30%,' + k.s3 + ' 62%,' + k.s4 + ' 86%,' + k.s5 + ')';
  }
  // Stories move on by themselves, as in Instagram; holding pauses them.
  storyTick() {
    const s = this.state || {};
    const st = s.story;
    if (!st) return;
    if ((s.turned || {})['story:0']) { this.setState({ story: Object.assign({}, st, { t: st.t + 120 }) }); return; }
    if (Date.now() - st.t < 3600) { this.setState({ now: Date.now() }); return; }
    this.storyStep(1);
  }
  storyHours() { const out = []; for (let h = 13; h <= 23; h++) out.push(h); return out.concat([8, 9, 10, 11, 12]); }
  storyList(h) {
    const s = this.state || {};
    const { ev } = this.events();
    const cat = s.cat || 'all';
    return ev.filter((e) => e.h === h && (cat === 'all' || this.catOf(e) === cat));
  }
  storyStep(dir) {
    const s = this.state || {};
    const st = s.story;
    if (!st) return;
    const list = this.storyList(st.h);
    const hours = this.storyHours().filter((h) => this.storyList(h).length);
    let { h, i } = st;
    i += dir;
    if (i >= list.length) {
      const k = hours.indexOf(h) + 1;
      if (k >= hours.length) { this.setState({ story: null }); return; }
      h = hours[k]; i = 0;
    } else if (i < 0) {
      const k = hours.indexOf(h) - 1;
      if (k < 0) i = 0;
      else { h = hours[k]; i = this.storyList(h).length - 1; }
    }
    const seen = Object.assign({}, s.seen); seen[h] = true;
    const turned = Object.assign({}, s.turned); delete turned['story:0'];
    this.setState({ story: { h, i, t: Date.now() }, seen, sel: 0, plan: false, turned });
  }
  // The swipe deck.
  deckPool() {
    const s = this.state || {};
    const { ev } = this.events();
    const cat = s.cat || 'all';
    return ev.filter((e) => e.h >= 13 && (cat === 'all' || this.catOf(e) === cat));
  }
  decide(e, dir) {
    const s = this.state || {};
    if (s.fly) return;
    this.setState({ fly: { n: e.n, dir }, ddx: 0, ddy: 0 });
    clearTimeout(this._flyT);
    this._flyT = setTimeout(() => {
      const st = this.state || {};
      const decided = Object.assign({}, st.decided); decided[e.n] = dir > 0 ? 'save' : 'skip';
      const saved = Object.assign({}, st.saved);
      if (dir > 0) saved[e.n] = true;
      this.setState({ decided, saved, fly: null, hist: (st.hist || []).concat([e.n]), pasted: dir > 0 ? e.n : st.pasted });
    }, 320);
  }
  undo() {
    const s = this.state || {};
    const hist = (s.hist || []).slice();
    const n = hist.pop();
    if (!n) return;
    const decided = Object.assign({}, s.decided); delete decided[n];
    const saved = Object.assign({}, s.saved); delete saved[n];
    this.setState({ decided, saved, hist });
  }
  cardDown(e) { this._dd = { x: e.clientX, y: e.clientY, moved: false }; try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) { /* fine */ } }
  cardMove(e) {
    const d = this._dd;
    if (!d) return;
    const dx = e.clientX - d.x, dy = e.clientY - d.y;
    if (!d.moved && Math.abs(dx) + Math.abs(dy) < 6) return;
    d.moved = true;
    this.setState({ ddx: dx, ddy: dy });
  }
  cardUp(ev) {
    const d = this._dd;
    this._dd = null;
    if (!d) return;
    const s = this.state || {};
    if (!d.moved) { this.setState({ sel: ev.n, plan: false }); return; }
    if (Math.abs(s.ddx || 0) > 90) this.decide(ev, (s.ddx || 0) > 0 ? 1 : -1);
    else this.setState({ ddx: 0, ddy: 0 });
  }
  // Scroll the day: the band's sky follows the hour scrolled to.
  scrolled(e) {
    const top = e.currentTarget.scrollTop;
    this._st = top;
    if (this._raf) return;
    this._raf = requestAnimationFrame(() => { this._raf = 0; this.setState({ scrollTop: this._st }); });
  }
  renderVals() {
    this._forceClear = typeof this.isPast === 'function' ? this.isPast() : false;
    const v = this.baseVals();
    const c = this.cfg();
    const s = Object.assign({ cat: 'all', seen: {}, story: null, decided: {}, hist: [], fly: null, ddx: 0, ddy: 0, exp: {}, saved: {}, scrollTop: 0, pasted: 0 }, this.state || {});
    const { ev, two } = this.events();
    const catOk = (e) => s.cat === 'all' || this.catOf(e) === s.cat;
    const go = (patch) => () => this.setState(patch);
    const DOT = __CATS__;
    v.chips = ['all', 'Culture', 'Games', 'Outdoors', 'Language'].map((id) => {
      const on = s.cat === id;
      return { label: id === 'all' ? 'Everything' : id, on, pick: go({ cat: id, story: null }), dot: id === 'all' ? 'conic-gradient(#4a2bc0 0 25%,#f2c94c 0 50%,#2f8a4a 0 75%,#8a73d6 0)' : DOT[id],
        style: on ? 'background:#211d15;color:#fffdf8;border-color:#211d15' : 'background:#fffdf8;color:#211d15;border-color:#e6ddcb' };
    });
    const thumb = (e, w, h) => Object.assign({}, e, { w, h, saved: !!s.saved[e.n], open: () => this.setState({ sel: e.n, plan: false }),
      cap1: e.ph ? two(e.h) + ':00 hour' : e.time + ' · ' + e.place, cap2: e.ph ? 'Placeholder #' + e.n : e.title });
    if (c.model === 'shelves') {
      const parts = [
        { id: 'soon', name: 'Starting soon', from: 13, to: 14, at: 13.6 },
        { id: 'aft', name: 'This afternoon', from: 15, to: 16, at: 15.8 },
        { id: 'gold', name: 'Golden hour', from: 17, to: 19, at: 18.7 },
        { id: 'dark', name: 'After dark', from: 20, to: 23, at: 21.6 },
        { id: 'early', name: 'Earlier today', from: 8, to: 12, at: 8.6 },
      ];
      v.shelves = parts.filter((pt) => ev.some((e) => e.h >= pt.from && e.h <= pt.to && catOk(e))).map((pt) => {
        const all = ev.filter((e) => e.h >= pt.from && e.h <= pt.to && catOk(e));
        const open = !!s.exp[pt.id];
        const shown = open ? all : all.slice(0, c.shelfN);
        const k = this.sky(pt.at);
        const dark = pt.id === 'dark' || pt.id === 'gold';
        return {
          name: pt.name, sub: two(pt.from) + ':00–' + two(pt.to) + ':59 · ' + all.length + ' events',
          bg: 'linear-gradient(180deg,' + k.s1 + ',' + k.s3 + ' 55%,' + k.s5 + ')', fg: dark ? '#fffdf8' : '#211d15',
          sunStyle: 'background:' + (pt.id === 'dark' ? '#f4f1dc' : k.sunC) + ';box-shadow:0 0 0 10px ' + (pt.id === 'dark' ? '#f4f1dc22' : k.sunC + '33') + ',0 0 40px 18px ' + (pt.id === 'dark' ? '#f4f1dc33' : k.sunC + '55'),
          stars: pt.id === 'dark', items: shown.map((e) => thumb(e, c.thumbW, c.thumbH)),
          hasMore: !open && all.length > shown.length, more: '+' + (all.length - shown.length), allLabel: open ? 'Show less' : 'See all',
          toggle: () => { const x = Object.assign({}, s.exp); x[pt.id] = !open; this.setState({ exp: x }); },
          rowStyle: open ? 'flex-wrap:wrap;overflow-x:visible' : 'flex-wrap:nowrap;overflow-x:auto',
          empty: !all.length,
        };
      });
    }
    if (c.model === 'stories') {
      v.rings = this.storyHours().filter((h) => this.storyList(h).length).map((h) => {
        const list = this.storyList(h);
        const seen = !!s.seen[h];
        return { label: h === 13 ? 'Now' : two(h) + ':00', cover: list.length ? list[0].src : '', art: list.length ? this.mxArt(list[0]) : '', has: list.length > 0, aria: two(h) + ':00 · ' + list.length + ' events',
          ring: !list.length ? '#e6ddcb' : seen ? '#cdbfa3' : 'conic-gradient(from 200deg,#f2c94c,#ef7d2a,#c23b0e,#e8cc6e,#f2c94c)', fw: seen ? 500 : 700, op: list.length ? 1 : 0.4,
          open: () => { if (!list.length) return; const sn = Object.assign({}, s.seen); sn[h] = true; this.setState({ story: { h, i: 0, t: Date.now() }, seen: sn, sel: 0 }); } };
      });
      if (c.feed) {
        const onWall = this.pool().slice(0, c.per).map((e) => e.n);
        const order = [13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 8, 9, 10, 11, 12];
        v.feed = order.map((h) => {
          const list = this.storyList(h);
          const posts = list.filter((e) => onWall.indexOf(e.n) < 0).map((e) => Object.assign(thumb(e, c.postW, c.postH), {
            rot: [-1.2, 0.9, -0.5, 1.3][e.n % 4],
            saveLabel: s.saved[e.n] ? 'Saved' : 'Save', saveStyle: s.saved[e.n] ? 'background:#c23b0e;color:#fff;border-color:#c23b0e' : 'background:#fffdf8;color:#211d15;border-color:#211d15',
            save: () => { const sv = Object.assign({}, s.saved); if (sv[e.n]) delete sv[e.n]; else sv[e.n] = true; this.setState({ saved: sv }); },
            watch: () => { const sn = Object.assign({}, s.seen); sn[h] = true; this.setState({ story: { h, i: list.indexOf(e), t: Date.now() }, seen: sn, sel: 0 }); },
            line1: e.ph ? two(h) + ':00 hour · Placeholder #' + e.n : e.time + ' · ' + e.title,
            line2: e.ph ? 'Not a real event · one of 100 on a busy day' : e.place + ' · ' + e.until + ' · ' + e.price,
          }));
          const k = this.sky(h + 0.5);
          return { id: 'g' + h, sign: two(h) + ':00', sub: list.length + (list.length === 1 ? ' event' : ' events') + ' · ' + (h < 12 ? 'Earlier today' : h < 17 ? 'Afternoon' : h < 20 ? 'Golden hour' : 'After dark'),
            posts, has: posts.length > 0, tint: 'linear-gradient(180deg,' + k.s2 + '40,' + k.s4 + '00 220px)' };
        }).filter((x) => x.has);
      }
      v.storyOpen = !!s.story;
      if (s.story) {
        const list = this.storyList(s.story.h);
        const e = list[Math.min(s.story.i, list.length - 1)] || ev[0];
        const t = s.press ? (s.pressAt - s.story.t) : (Date.now() - s.story.t);
        const prog = Math.max(0, Math.min(1, t / 3600));
        const k = this.sky(s.story.h + 0.5);
        v.st = {
          bars: list.map((x, j) => ({ w: (j < s.story.i ? 100 : j === s.story.i ? Math.round(prog * 100) : 0) + '%' })),
          bg: 'linear-gradient(180deg,' + k.s1 + ',' + k.s2 + ' 30%,' + k.s3 + ' 60%,' + k.s5 + ')',
          title: two(s.story.h) + ':00', count: (s.story.i + 1) + ' of ' + list.length, weather: k.temp + ' ' + k.name,
          p: thumb(e, c.storyW, c.storyH), line1: e.ph ? 'Layout placeholder #' + e.n : e.title,
          line2: e.ph ? 'Not a real event · one of 100 on a busy day' : e.time + ' · ' + e.until + ' · ' + e.place + ' · ' + e.price + (e.titleEnglish ? ' · ' + e.titleEnglish : ''),
          saveLabel: s.saved[e.n] ? 'Saved' : 'Save', saveStyle: s.saved[e.n] ? 'background:#c23b0e;color:#fff;border-color:#c23b0e' : 'background:#ffffff1f;color:#fff;border-color:#ffffff99',
          save: (x) => this.mxSave(e, x),
          more: () => this.mxOpen(e, 'about'),
          real: !e.ph, plan: go({ plan: !s.plan }), planOpen: !!s.plan, planText: 'In the app, this opens Travel with the way to ' + (e.place || '') + '. Not connected in this preview.',
          next: () => { if (Date.now() - (this._swiped || 0) < 400) return; this.storyStep(1); },
          prev: () => { if (Date.now() - (this._swiped || 0) < 400) return; this.storyStep(-1); },
          close: go({ story: null, plan: false }),
          hold: (x) => { this._sy = x && x.clientY; this.setState({ press: true, pressAt: Date.now() }); },
          letgo: (x) => {
            const st = this.state || {};
            if (!st.press) return;
            const dy = x && typeof this._sy === 'number' ? x.clientY - this._sy : 0;
            this._sy = null;
            this.setState({ press: false, story: Object.assign({}, st.story, { t: st.story.t + (Date.now() - st.pressAt) }) });
            // Swipe up for the next poster, down for the one before.
            if (Math.abs(dy) > 60) { this._swiped = Date.now(); this.storyStep(dy < 0 ? 1 : -1); }
          },
        };
      } else v.st = { bars: [], p: thumb(ev[0], 10, 10) };
    }
    if (c.model === 'swipe') {
      const pool = this.deckPool();
      const left = pool.filter((e) => !s.decided[e.n]);
      const top3 = left.slice(0, 3);
      v.deck = top3.map((e, i) => {
        let style;
        if (i === 0 && s.fly && s.fly.n === e.n) style = 'transform:translate(' + s.fly.dir * 640 + 'px,' + (s.ddy || 0) + 'px) rotate(' + s.fly.dir * 26 + 'deg);transition:transform 320ms cubic-bezier(.4,0,.8,.4);z-index:3';
        else if (i === 0) style = 'transform:translate(' + s.ddx + 'px,' + s.ddy + 'px) rotate(' + s.ddx / 16 + 'deg);transition:' + (s.ddx || s.ddy ? 'none' : 'transform 260ms cubic-bezier(.22,1,.36,1)') + ';z-index:3;cursor:grab';
        else style = 'transform:translateY(' + i * 12 + 'px) scale(' + (1 - i * 0.05) + ');transition:transform 300ms ease;z-index:' + (3 - i) + ';filter:brightness(' + (1 - i * 0.12) + ')';
        return Object.assign(thumb(e, c.deckW, c.deckH), { style, top: i === 0,
          yes: i === 0 ? Math.max(0, Math.min(1, s.ddx / 100)) : 0, no: i === 0 ? Math.max(0, Math.min(1, -s.ddx / 100)) : 0,
          down: (x) => { if (i === 0) this.cardDown(x); }, move: (x) => { if (i === 0) this.cardMove(x); }, up: () => { if (i === 0) this.cardUp(e); } });
      }).reverse();
      const done = Object.keys(s.decided).length;
      v.deckEmpty = !left.length;
      v.deckCount = left.length ? 'From 13:10 · ' + Math.min(pool.length, pool.length - left.length + 1) + ' of ' + pool.length : 'That’s every event from 13:10';
      v.skip = () => { if (left[0]) this.decide(left[0], -1); };
      v.keep = () => { if (left[0]) this.decide(left[0], 1); };
      v.undoIt = () => this.undo();
      v.canUndo = (s.hist || []).length > 0;
      const savedList = ev.filter((e) => s.saved[e.n]);
      v.savedCount = 'Your night · ' + savedList.length;
      v.hasSaved = savedList.length > 0;
      v.pastes = (c.slots.length ? savedList.slice(-c.slots.length) : []).map((e, i) => Object.assign(thumb(e, c.slots[i][2], c.slots[i][2] * 1.4), { x: c.slots[i][0], y: c.slots[i][1], rot: c.slots[i][3], fresh: s.pasted === e.n ? 'wp-paste' : '' }));
      v.tray = !!s.tray; v.openTray = go({ tray: !s.tray });
      v.trayItems = savedList.map((e) => thumb(e, 96, 134));
    }
    if (c.model === 'scroll') {
      const secs = [];
      let y = c.secStart;
      for (let h = 8; h <= 23; h++) {
        const list = ev.filter((e) => e.h === h && catOk(e));
        if (!list.length) continue;
        const items = [];
        let row = 0, col = 0;
        list.forEach((e) => {
          if (!e.ph) {
            if (col) { row++; col = 0; }
            items.push(Object.assign(thumb(e, c.featW, c.featH), { x: (c.mW - c.featW) / 2, y: c.signH + 16 + row * c.rowH, rot: -1, feat: true }));
            row += c.featRows; col = 0;
            return;
          }
          const x = c.padX + col * c.colW + (c.colW - c.cardW) / 2;
          items.push(Object.assign(thumb(e, c.cardW, c.cardH), { x, y: c.signH + 16 + row * c.rowH + (col % 2 ? c.stagger : 0), rot: [-1.6, 1.2, -0.6, 1.8, -1.1][(e.n) % 5], feat: false }));
          col++;
          if (col >= c.mcols) { col = 0; row++; }
        });
        const rows = row + (col ? 1 : 0);
        const height = c.signH + 16 + rows * c.rowH + c.stagger + 30;
        const k = this.sky(h + 0.5);
        secs.push({ h, id: 'g' + h, top: y, height, items, sign: two(h) + ':00', sub: list.length + (list.length === 1 ? ' event' : ' events') + ' · ' + (h < 12 ? 'Morning' : h < 17 ? 'Afternoon' : h < 20 ? 'Golden hour' : 'After dark'),
          tint: 'linear-gradient(180deg,' + k.s3 + '22,' + k.s5 + '00 40%)' });
        y += height;
      }
      // Which hour is under the band?
      const at = s.scrollTop + c.bandTop + c.bandH + 40;
      let cur = secs[0], frac = 0;
      secs.forEach((sc) => { if (at >= sc.top) { cur = sc; frac = Math.min(0.99, (at - sc.top) / sc.height); } });
      const before = s.scrollTop + c.bandTop < c.secStart - c.bandH;
      const hour = before ? 13 + 10 / 60 : cur.h + frac;
      const kb = this.sky(hour);
      v.kb = kb;
      v.sections = secs.map((sc) => Object.assign(sc, { heightPx: sc.height + 'px' }));
      v.bandHour = before ? 'Now · 13:10' : two(Math.floor(hour)) + ':' + two(Math.floor((hour % 1) * 60));
      v.bandPart = before ? 'Scroll down through the day' : cur.sub.split(' · ')[1];
      v.dayPos = Math.round(((hour - 8) / 16) * 100) + '%';
      v.onScroll = (e) => this.scrolled(e);
    }
    if (c.chrome) this.chrome(v);
    if (c.mxSizes) this.mxDress(v);
    return v;
  }
'''


def logic(cfg_json, real=False):
    L = GS.LOGIC.replace('  renderVals() {\n', '  baseVals() {\n', 1)
    L = L.replace("    if (!this._still) this._timer = setInterval(() => this.tick(), 6000);",
                  "    if (!this._still) this._timer = setInterval(() => this.tick(), 6000);\n    if (this.cfg().model === 'stories') this._story = setInterval(() => { const s = this.state || {}; if (s.story && !s.press) this.storyTick(); }, 120);\n    // A board that shows the details sheet open, for the canvas overview.\n    if (this.cfg().openSheet) { const e = this.events().ev.find((x) => x.id === this.cfg().openSheet); if (e) this.mxOpen(e, 'about'); }")
    L = L.replace("componentWillUnmount() { clearInterval(this._timer); clearTimeout(this._snap); }",
                  "componentWillUnmount() { clearInterval(this._timer); clearInterval(this._story); clearTimeout(this._snap); clearTimeout(this._flyT); }")
    # The column holds while a story or a card is moving.
    L = L.replace("if (s.hold || s.sel) return;", "if (s.hold || s.sel || s.story) return;")
    end = L.rstrip().rfind('}')
    L = L[:end] + EXTRA + SU.LOGIC + (SR.LOGIC + MX.logic() + MX_DRESS if real else '') + '}\n'
    return (L.replace('__CFG__', cfg_json).replace('__AMAP__', json.dumps(AMAP))
            .replace('__STYLES__', json.dumps(GS.STYLES)).replace('__CATS__', json.dumps(CATS)).replace('__CATALOGUE__', SR.CAT_JSON))


LIFT_ZOOM = {True: round(340 / 300, 4), False: round(380 / 300, 4)}
MX_DRESS = r'''
  // The mock's posters: each instance with its own state, sized as the mock sizes them.
  mxDress(v) {
    const c = this.cfg(), z = c.mxSizes;
    const { ev } = this.events();
    const order = ev.filter((e) => this.match(e)).map((e) => e.n);
    const wall = (p, k) => {
      const i = Math.max(0, order.indexOf(p.n));
      return this.mxPoster(p, 'wall', p.n, '--i:' + (k % 6) + ';rotate:' + (i % 2 === 0 ? 1.4 : -1.1) + 'deg;' + (c.phone ? 'width:' + z.wallW + 'px;height:' + z.wallH + 'px' : 'zoom:' + z.wallZoom));
    };
    let k = 0;
    if (v.grid) v.grid = v.grid.map((p) => wall(p, c.phone ? 0 : k++));
    if (v.bays) v.bays = v.bays.map((b) => Object.assign({}, b, { posters: (b.posters || []).map((p) => wall(p, k++)) }));
    if (v.strip) v.strip = v.strip.map((q, i) => this.mxPoster(q, 'column', i, c.phone ? 'width:' + z.colW + 'px;height:' + z.colH + 'px' : 'width:364px;height:560px;zoom:' + z.colZoom));
    if (v.st && v.st.p && v.storyOpen) v.st.p = this.mxPoster(v.st.p, 'story', 0, c.phone ? 'width:300px;height:420px' : 'zoom:' + z.storyZoom);
    const s = this.state || {};
    v.liftOpen = !!s.lift;
    const le = s.lift ? ev.find((e) => e.n === s.lift.n) : null;
    v.LP = le ? this.mxPoster(le, 'lift', 0, 'width:300px;height:470px;rotate:0deg;zoom:' + z.liftZoom) : {};
    v.liftCls = s.lift && s.lift.out ? ' is-out' : '';
    v.liftCardCls = s.lift && !s.lift.started ? ' is-pending' : '';
    v.liftDrop = () => this.mxDrop();
  }
'''


# ── Shared pieces ──────────────────────────────────────────────────────────
def chips(pad):
    return f'''<div role="group" aria-label="Category" style="display:flex;gap:8px;overflow-x:auto;padding:{pad};scrollbar-width:none">
  <sc-for list="{H('chips')}" as="c" hint-placeholder-count="5">
    <button type="button" aria-pressed="{H('c.on')}" onClick="{H('c.pick')}" style="flex:none;display:flex;align-items:center;gap:8px;height:40px;padding:0 15px 0 12px;border:1.5px solid;border-radius:999px;font:inherit;font-size:14px;font-weight:600;cursor:pointer;{H('c.style')}"><i style="display:block;width:12px;height:12px;border-radius:50%;background:{H('c.dot')}"></i>{H('c.label')}</button>
  </sc-for>
</div>'''


def poster_btn(var, extra_style=''):
    return (f'<button type="button" onClick="{H(var + ".open")}" aria-label="{H(var + ".alt")}" style="position:relative;display:block;flex:none;width:{H(var + ".w")}px;height:{H(var + ".h")}px;padding:0;border:0;background:none;cursor:pointer;{extra_style}">'
            f'<dc-import name="WallPoster" p="{H(var)}" hint-size="100%,100%"></dc-import><span aria-hidden="true" style="position:absolute;inset:0;z-index:3"></span></button>')


def details_strip(phone):
    """The poster you tapped, as a strip at the bottom of the screen."""
    return f'''<sc-if value="{H('hasPanel')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="{H('panel.title')}" style="position:absolute;left:{12 if phone else 'calc(50% - 300px)'};right:{12 if phone else 'calc(50% - 300px)'};bottom:{72 if phone else 24}px;z-index:45;padding:14px 16px;border-radius:18px;background:#fffdf8;box-shadow:0 18px 40px -14px rgba(20,12,4,.55);font-size:14px;line-height:1.35">
    <div style="display:flex;align-items:flex-start;gap:12px">
      <div style="flex:1;min-width:0"><strong style="display:block;font-size:17px;letter-spacing:-.3px">{H('panel.title')}</strong><span style="display:block;color:#4d4639">{H('panel.line')}</span><span style="display:block;margin-top:2px;color:#6e6657;font-size:12.5px">{H('panel.note')}</span></div>
      <button type="button" aria-label="Close" onClick="{H('closePanel')}" style="display:grid;place-items:center;width:44px;height:44px;margin:-8px -8px 0 0;border:0;border-radius:12px;background:none;cursor:pointer;font-size:22px;color:#211d15">×</button>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
      <button type="button" aria-pressed="{H('panel.savePressed')}" onClick="{H('panel.save')}" style="height:44px;padding:0 16px;border:1.5px solid #211d15;border-radius:12px;background:#fffdf8;font:inherit;font-weight:600;cursor:pointer">{H('panel.saveLabel')}</button>
      <sc-if value="{H('panel.showPlan')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('panel.plan')}" style="height:44px;padding:0 16px;border:0;border-radius:12px;background:#c23b0e;color:#fff;font:inherit;font-weight:600;cursor:pointer">Plan the way</button></sc-if>
    </div>
    <sc-if value="{H('panel.planOpen')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:8px 0 0;padding:8px 10px;border-radius:10px;background:#def5f8;color:#00566a;font-size:12.5px">{H('panel.planText')}</p></sc-if>
  </div>
</sc-if>'''


# ── 1 · Shelves ────────────────────────────────────────────────────────────
def shelves(phone):
    m = '14px 12px 0' if phone else '18px 32px 0'
    stars = ''.join(f'<i style="position:absolute;left:{x}%;top:{y}px;width:2px;height:2px;border-radius:50%;background:#fffbe8;opacity:.8"></i>' for x, y in ((62, 14), (70, 30), (78, 10), (86, 26), (92, 12), (74, 46), (58, 36)))
    return f'''{chips('16px 12px 2px' if phone else '22px 32px 2px')}
<sc-for list="{H('shelves')}" as="sh" hint-placeholder-count="3">
  <section style="position:relative;margin:{m};padding:16px 0 14px;border-radius:24px;background:{H('sh.bg')};color:{H('sh.fg')};overflow:hidden">
    <i aria-hidden="true" style="position:absolute;right:{26 if phone else 60}px;top:18px;width:{26 if phone else 34}px;height:{26 if phone else 34}px;border-radius:50%;{H('sh.sunStyle')}"></i>
    <sc-if value="{H('sh.stars')}" hint-placeholder-val="{{{{ false }}}}"><span aria-hidden="true">{stars}</span></sc-if>
    <div style="position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;padding:0 {16 if phone else 24}px">
      <div><h3 style="margin:0;font-size:{21 if phone else 26}px;letter-spacing:-.5px">{H('sh.name')}</h3><div style="font-size:12.5px;opacity:.85">{H('sh.sub')}</div></div>
      <button type="button" onClick="{H('sh.toggle')}" style="flex:none;height:36px;padding:0 14px;border:0;border-radius:999px;background:#fffdf8e6;color:#211d15;font:inherit;font-size:13px;font-weight:700;cursor:pointer;margin-right:{48 if phone else 90}px">{H('sh.allLabel')}</button>
    </div>
    <div style="position:relative;display:flex;gap:{12 if phone else 16}px;padding:14px {16 if phone else 24}px 4px;scroll-snap-type:x mandatory;scrollbar-width:none;{H('sh.rowStyle')}">
      <sc-for list="{H('sh.items')}" as="P" hint-placeholder-count="4">
        <div style="flex:none;scroll-snap-align:start;width:{H('P.w')}px">
          {poster_btn('P', 'filter:drop-shadow(0 8px 12px rgba(0,0,0,.25))')}
          <div style="margin-top:7px;font-size:12px;line-height:1.25"><strong style="display:block">{H('P.cap1')}</strong><span style="opacity:.85">{H('P.cap2')}</span></div>
        </div>
      </sc-for>
      <sc-if value="{H('sh.hasMore')}" hint-placeholder-val="{{{{ false }}}}">
        <button type="button" onClick="{H('sh.toggle')}" style="flex:none;display:grid;place-items:center;width:{110 if phone else 150}px;height:{172 if phone else 210}px;border:2px dashed currentColor;border-radius:8px;background:#ffffff22;color:inherit;font:inherit;font-size:22px;font-weight:800;cursor:pointer;opacity:.9"><span>{H('sh.more')}<span style="display:block;font-size:12.5px;font-weight:600">See all</span></span></button>
      </sc-if>
    </div>
  </section>
</sc-for>
<div style="height:{90 if phone else 40}px"></div>'''


# ── 2 · Stories ────────────────────────────────────────────────────────────
def rings(phone):
    size = 66 if phone else 84
    return f'''<div style="display:flex;gap:{12 if phone else 18}px;overflow-x:auto;padding:{'10px 14px 8px' if phone else '14px 32px 10px'};scrollbar-width:none">
  <sc-for list="{H('rings')}" as="r" hint-placeholder-count="6">
    <button type="button" onClick="{H('r.open')}" aria-label="{H('r.aria')}" style="flex:none;display:flex;flex-direction:column;align-items:center;gap:5px;padding:0;border:0;background:none;cursor:pointer;font:inherit;opacity:{H('r.op')}">
      <span style="display:grid;place-items:center;width:{size + 8}px;height:{size + 8}px;border-radius:50%;background:{H('r.ring')}"><span class="mx mx-art" style="display:block;width:{size}px;height:{size}px;border-radius:50%;border:3px solid #faf6ee;background:#e6ddcb">{MX.art_markup('r.art')}</span></span>
      <span style="font-size:12.5px;font-weight:{H('r.fw')}">{H('r.label')}</span>
    </button>
  </sc-for>
</div>'''


def story_viewer(phone):
    w, h = (300, 420) if phone else (380, 532)
    shell = ('position:absolute;inset:0;z-index:60;display:flex;flex-direction:column;color:#fff;background:' + H('st.bg')) if phone else \
            ('position:absolute;left:calc(50% - 230px);top:110px;width:460px;height:900px;z-index:40;display:flex;flex-direction:column;color:#fff;border-radius:22px;overflow:hidden;box-shadow:0 30px 80px -20px #000a;background:' + H('st.bg'))
    backdrop = '' if phone else f'<div onClick="{H("st.close")}" style="position:absolute;inset:0;z-index:39;background:rgba(20,16,30,.62);backdrop-filter:blur(3px)"></div>'
    outer = '' if phone else f'''<button type="button" aria-label="Previous" onClick="{H('st.prev')}" style="{ARROW_BTN}; left:calc(50% - 300px); top:520px; z-index:41">{LEFT}</button>
  <button type="button" aria-label="Next" onClick="{H('st.next')}" style="{ARROW_BTN}; left:calc(50% + 252px); top:520px; z-index:41">{RIGHT}</button>'''
    return f'''<sc-if value="{H('storyOpen')}" hint-placeholder-val="{{{{ false }}}}">
  {backdrop}{outer}
  <div role="dialog" aria-label="Stories at {H('st.title')}" style="{shell}">
    <div style="display:flex;gap:4px;padding:12px 12px 0">
      <sc-for list="{H('st.bars')}" as="b" hint-placeholder-count="5"><span style="flex:1;height:3px;border-radius:2px;background:rgba(255,255,255,.35);overflow:hidden"><i style="display:block;height:100%;width:{H('b.w')};background:#fff"></i></span></sc-for>
    </div>
    <div style="display:flex;align-items:center;gap:10px;padding:12px 8px 0 14px">
      <span style="display:flex;align-items:center;height:30px;padding:0 10px;border-radius:7px;background:#1d4d8f;box-shadow:inset 0 0 0 2px #fff;font-weight:800;font-variant-numeric:tabular-nums">{H('st.title')}</span>
      <span style="font-size:13px;line-height:1.2;text-shadow:0 1px 2px #0006"><strong>{H('st.count')}</strong><br>{H('st.weather')} · sample weather</span>
      <button type="button" aria-label="Close stories" onClick="{H('st.close')}" style="margin-left:auto;width:44px;height:44px;border:0;background:none;color:#fff;font-size:26px;cursor:pointer">×</button>
    </div>
    <div style="position:relative;flex:1;display:grid;place-items:center;touch-action:none" onPointerDown="{H('st.hold')}" onPointerUp="{H('st.letgo')}" onPointerLeave="{H('st.letgo')}">
      <button type="button" aria-label="Previous poster" onClick="{H('st.prev')}" style="position:absolute;left:0;top:0;bottom:0;width:calc((100% - {w}px) / 2);border:0;background:none;cursor:pointer"></button>
      <button type="button" aria-label="Next poster" onClick="{H('st.next')}" style="position:absolute;right:0;top:0;bottom:0;width:calc((100% - {w}px) / 2);border:0;background:none;cursor:pointer"></button>
      <div class="mx" style="position:relative;width:{w}px;height:{h}px;filter:drop-shadow(0 20px 30px rgba(0,0,0,.4))">{MX.poster_markup('st.p', 'story')}</div>
    </div>
    <div style="padding:40px 16px {34 if phone else 22}px;margin-top:-40px;background:linear-gradient(180deg,rgba(15,10,30,0),rgba(15,10,30,.62) 45%,rgba(15,10,30,.78))">
      <strong style="display:block;font-size:18px;text-shadow:0 1px 3px #0007">{H('st.line1')}</strong>
      <span style="display:block;font-size:13px;opacity:.95;text-shadow:0 1px 3px #0007">{H('st.line2')}</span>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button type="button" onClick="{H('st.save')}" style="height:44px;padding:0 18px;border:1.5px solid;border-radius:999px;font:inherit;font-weight:700;cursor:pointer;{H('st.saveStyle')}">{H('st.saveLabel')}</button>
        <button type="button" onClick="{H('st.more')}" style="height:44px;padding:0 18px;border:0;border-radius:999px;background:#fffdf8;color:#211d15;font:inherit;font-weight:700;cursor:pointer">More about it</button>
      </div>
      <sc-if value="{H('st.planOpen')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:8px 0 0;padding:8px 10px;border-radius:10px;background:#fffdf8;color:#00566a;font-size:12.5px">{H('st.planText')}</p></sc-if>
    </div>
  </div>
</sc-if>'''


def feed():
    """The phone's feed: the day's posters down the wall under sticky hour
    signs, each with Save and Watch, as in Instagram's feed."""
    sign = 'display:inline-flex;align-items:center;gap:10px;padding:6px 14px 6px 8px;border-radius:10px;background:#1d4d8f;box-shadow:inset 0 0 0 2.5px #fff,0 6px 14px -8px #0008;color:#fff'
    tape = '<span aria-hidden="true" style="position:absolute;left:50%;top:-9px;width:54px;height:18px;margin-left:-27px;background:#fffbe9b3;transform:rotate(-3deg);z-index:2"></span>'
    return f'''<div style="position:relative;margin:10px 12px 0;border-radius:24px;background:#e7dbc4 url({AS('wall-tile.jpg')}) 0 0 / 480px 480px repeat;box-shadow:inset 0 0 0 1px #d8ccb4">
  <sc-for list="{H('feed')}" as="sc" hint-placeholder-count="2">
    <section id="{H('sc.id')}" style="position:relative;padding:0 0 18px;background:{H('sc.tint')}">
      <div style="position:sticky;top:0;z-index:10;padding:10px 14px 4px"><span style="{sign}"><strong style="display:grid;place-items:center;height:30px;padding:0 8px;border-radius:6px;background:#fff;color:#1d4d8f;font-size:16px;font-variant-numeric:tabular-nums">{H('sc.sign')}</strong><span style="font-size:13px;font-weight:600">{H('sc.sub')}</span></span></div>
      <sc-for list="{H('sc.posts')}" as="P" hint-placeholder-count="2">
        <article style="display:flex;flex-direction:column;align-items:center;padding:22px 0 8px">
          <div style="position:relative;transform:rotate({H('P.rot')}deg)">{tape}{poster_btn('P', 'filter:drop-shadow(0 12px 16px rgba(40,25,10,.32))')}</div>
          <div style="width:{H('P.w')}px;margin-top:12px;padding:10px 12px;border-radius:14px;background:#fffdf8f0;box-shadow:0 6px 16px -10px #0006">
            <strong style="display:block;font-size:14px">{H('P.line1')}</strong>
            <span style="display:block;color:#6e6657;font-size:12.5px">{H('P.line2')}</span>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="button" onClick="{H('P.save')}" style="height:40px;padding:0 14px;border:1.5px solid;border-radius:999px;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;{H('P.saveStyle')}">{H('P.saveLabel')}</button>
              <button type="button" onClick="{H('P.watch')}" style="display:flex;align-items:center;gap:6px;height:40px;padding:0 14px;border:0;border-radius:999px;background:#211d15;color:#fffdf8;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer"><i style="display:block;width:14px;height:14px;border-radius:50%;background:conic-gradient(from 200deg,#f2c94c,#ef7d2a,#c23b0e,#e8cc6e,#f2c94c)"></i>Watch</button>
            </div>
          </div>
        </article>
      </sc-for>
    </section>
  </sc-for>
  <div style="padding:26px 20px 110px;text-align:center;color:#4d4639"><strong style="display:block;font-size:17px">That’s the day.</strong><a href="#wall" style="color:#00566a;font-weight:700">Back to now</a></div>
</div>'''


# ── 3 · Swipe ──────────────────────────────────────────────────────────────
def deck(cfgd):
    x, y, w, h = cfgd['deckBox']
    stamp = 'position:absolute;top:22px;padding:4px 12px;border:3px solid;border-radius:8px;font-size:20px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;pointer-events:none;background:#fffdf8d9'
    pastes = ''
    if cfgd.get('slots'):
        pastes = f'''<sc-for list="{H('pastes')}" as="P" hint-placeholder-count="0"><div class="{H('P.fresh')}" style="position:absolute;left:{H('P.x')}px;top:{H('P.y')}px;transform:rotate({H('P.rot')}deg);z-index:4">{poster_btn('P')}</div></sc-for>'''
    return f'''{pastes}
  <div style="position:absolute;left:{x}px;top:{y}px;width:{w}px;height:{h}px;z-index:8;touch-action:none">
    <sc-for list="{H('deck')}" as="d" hint-placeholder-count="3">
      <div style="position:absolute;inset:0;user-select:none;{H('d.style')}" onPointerDown="{H('d.down')}" onPointerMove="{H('d.move')}" onPointerUp="{H('d.up')}" onPointerCancel="{H('d.up')}">
        <div style="position:relative;width:100%;height:100%;filter:drop-shadow(0 18px 24px rgba(30,15,0,.35))"><dc-import name="WallPoster" p="{H('d')}" hint-size="100%,100%"></dc-import><span aria-hidden="true" style="position:absolute;inset:0;z-index:3"></span></div>
        <span style="{stamp};left:18px;color:#c23b0e;transform:rotate(-12deg);opacity:{H('d.yes')}">I’m in</span>
        <span style="{stamp};right:18px;color:#4d4639;transform:rotate(12deg);opacity:{H('d.no')}">Not tonight</span>
      </div>
    </sc-for>
    <sc-if value="{H('deckEmpty')}" hint-placeholder-val="{{{{ false }}}}"><div style="position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:20px;border-radius:10px;background:#fffdf8e6;font-size:15px"><span><strong style="display:block;font-size:20px">That’s the day.</strong>{H('savedCount')}</span></div></sc-if>
  </div>'''


def deck_controls(cfgd):
    bx, by = cfgd['btns']
    btn = 'display:grid;place-items:center;border-radius:50%;border:0;cursor:pointer;box-shadow:0 8px 18px -8px #0007;font:inherit;font-weight:800'
    return f'''<div style="position:absolute;left:{bx}px;top:{by}px;transform:translateX(-50%);display:flex;align-items:center;gap:16px;z-index:9">
    <button type="button" aria-label="Skip" onClick="{H('skip')}" style="{btn};width:58px;height:58px;background:#fffdf8;color:#4d4639;font-size:24px">✕</button>
    <button type="button" aria-label="Undo" onClick="{H('undoIt')}" style="{btn};width:46px;height:46px;background:#fffdf8;color:#00566a;font-size:20px;opacity:{'1' if True else '0'}">↺</button>
    <button type="button" aria-label="Save, I’m in" onClick="{H('keep')}" style="{btn};width:58px;height:58px;background:#c23b0e;color:#fff;font-size:24px">★</button>
  </div>
  <div style="position:absolute;left:{bx}px;top:{cfgd['countY']}px;transform:translateX(-50%);z-index:9;white-space:nowrap"><span style="display:inline-block;padding:5px 12px;border-radius:999px;background:#211d15d9;color:#fffdf8;font-size:12.5px;font-weight:700">{H('deckCount')}</span></div>
  <button type="button" onClick="{H('openTray')}" style="position:absolute;left:{cfgd['trayBtn'][0]}px;top:{cfgd['trayBtn'][1]}px;z-index:9;height:38px;padding:0 14px;border:0;border-radius:999px;background:#c23b0e;color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 14px -8px #000a">{H('savedCount')}</button>'''


def tray(phone):
    return f'''<sc-if value="{H('tray')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Your night" style="position:absolute;left:{12 if phone else 'calc(50% - 360px)'};right:{12 if phone else 'calc(50% - 360px)'};bottom:{72 if phone else 24}px;z-index:44;padding:14px 16px;border-radius:20px;background:#211d15;color:#fffdf8;box-shadow:0 18px 40px -14px #000a">
    <div style="display:flex;align-items:center;justify-content:space-between"><strong style="font-size:17px">{H('savedCount')}</strong><button type="button" aria-label="Close" onClick="{H('openTray')}" style="width:44px;height:44px;border:0;background:none;color:#fffdf8;font-size:22px;cursor:pointer">×</button></div>
    <div style="display:flex;gap:10px;overflow-x:auto;padding:8px 0 2px;scrollbar-width:none">
      <sc-for list="{H('trayItems')}" as="P" hint-placeholder-count="3"><div style="flex:none">{poster_btn('P')}</div></sc-for>
    </div>
    <p style="margin:8px 0 0;font-size:12.5px;opacity:.8">Saved posters stay on your wall. In the app, Composer can plan the evening around them.</p>
  </div>
</sc-if>'''


# ── 4 · Scroll the day ─────────────────────────────────────────────────────
def band(cfgb):
    W, BH = cfgb['W'], cfgb['bandH']
    p = cfgb['p'] + 'b'
    sky = A.skyline(p, W, BH - 6, seed=9, phone=cfgb['phone']).replace("{{k.", "{{kb.")
    defs = f'''<defs><linearGradient id="{p}sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:{H('kb.s1')};transition:stop-color .8s"/><stop offset=".35" style="stop-color:{H('kb.s2')};transition:stop-color .8s"/><stop offset=".7" style="stop-color:{H('kb.s3')};transition:stop-color .8s"/><stop offset="1" style="stop-color:{H('kb.s5')};transition:stop-color .8s"/></linearGradient>
  <linearGradient id="{p}haze" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:{H('kb.s5')};stop-opacity:0"/><stop offset="1" style="stop-color:{H('kb.s5')};stop-opacity:.55"/></linearGradient><filter id="{p}softer"><feGaussianBlur stdDeviation="6"/></filter></defs>'''
    return f'''<div style="position:sticky;top:{cfgb['bandTop']}px;z-index:20;height:{BH}px;margin:{cfgb['bandMargin']};border-radius:{18 if cfgb['phone'] else 22}px;overflow:hidden;box-shadow:0 10px 24px -14px #0007">
  <svg width="{W}" height="{BH}" viewBox="0 0 {W} {BH}" aria-hidden="true" style="position:absolute;inset:0">{defs}<rect width="{W}" height="{BH}" fill="url(#{p}sky)"/>
    <g style="opacity:{H('kb.stars')};transition:opacity .8s" fill="#fffbe8">{''.join(f'<circle cx="{x}" cy="{y}" r="1"/>' for x, y in ((W * .1, 14), (W * .22, 30), (W * .35, 12), (W * .5, 24), (W * .64, 10), (W * .78, 28), (W * .9, 16)))}</g>
    <g style="transform:translate({H('kb.sunX')}px,{H('kb.sunY')}px) scale(.55);opacity:{H('kb.sunO')};transition:transform .8s,opacity .8s"><circle r="34" style="fill:{H('kb.sunC')};opacity:.25"/><circle r="16" style="fill:{H('kb.sunC')}"/></g>
    <g style="transform:translate({H('kb.moonX')}px,{H('kb.moonY')}px) scale(.55);opacity:{H('kb.moonO')};transition:transform .8s,opacity .8s"><path d="{H('kb.moonPath')}" fill="#f4f1dc"/></g>
    {sky}
  </svg>
  <div style="position:absolute;left:{14 if cfgb['phone'] else 24}px;top:{10 if cfgb['phone'] else 16}px;color:#fff;text-shadow:0 1px 4px #0008">
    <strong style="display:block;font-size:{26 if cfgb['phone'] else 34}px;letter-spacing:-.8px;font-variant-numeric:tabular-nums">{H('bandHour')}</strong>
    <span style="font-size:12.5px;font-weight:600">{H('bandPart')} · {H('kb.temp')} {H('kb.name')}, sample weather</span>
  </div>
  <div aria-hidden="true" style="position:absolute;left:{14 if cfgb['phone'] else 24}px;right:{14 if cfgb['phone'] else 24}px;bottom:8px;height:3px;border-radius:2px;background:#ffffff55"><i style="position:absolute;left:{H('dayPos')};top:-4px;width:11px;height:11px;margin-left:-5px;border-radius:50%;background:#ffd27a;box-shadow:0 0 8px #ffd27a"></i></div>
</div>'''


def day_wall(cfgb):
    W = cfgb['W']
    sign = 'display:inline-flex;align-items:center;gap:10px;padding:6px 14px 6px 8px;border-radius:10px;background:#1d4d8f;box-shadow:inset 0 0 0 2.5px #fff,0 6px 14px -8px #0008;color:#fff'
    tape = '<span aria-hidden="true" style="position:absolute;left:50%;top:-8px;width:46px;height:16px;margin-left:-23px;background:#fffbe9b3;transform:rotate(-3deg);z-index:2"></span>'
    return f'''<div style="position:relative;margin:{cfgb['wallMargin']};border-radius:{24 if cfgb['phone'] else 28}px;background:#e7dbc4 url({AS('wall-tile.jpg')}) 0 0 / 480px 480px repeat;box-shadow:inset 0 0 0 1px #d8ccb4">
  <sc-for list="{H('sections')}" as="sc" hint-placeholder-count="3">
    <section id="{H('sc.id')}" style="position:relative;height:{H('sc.heightPx')};background:{H('sc.tint')}">
      <div style="position:sticky;top:{cfgb['bandTop'] + cfgb['bandH'] + 8}px;z-index:10;padding:10px {14 if cfgb['phone'] else 28}px 0"><span style="{sign}"><strong style="display:grid;place-items:center;height:30px;padding:0 8px;border-radius:6px;background:#fff;color:#1d4d8f;font-size:16px;font-variant-numeric:tabular-nums">{H('sc.sign')}</strong><span style="font-size:13px;font-weight:600">{H('sc.sub')}</span></span></div>
      <sc-for list="{H('sc.items')}" as="P" hint-placeholder-count="2">
        <div style="position:absolute;left:{H('P.x')}px;top:{H('P.y')}px;transform:rotate({H('P.rot')}deg)">{tape}{poster_btn('P', 'filter:drop-shadow(0 8px 10px rgba(40,25,10,.3))')}</div>
      </sc-for>
    </section>
  </sc-for>
  <div style="padding:30px 20px 120px;text-align:center;color:#4d4639"><svg width="44" height="44" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" fill="#1d1a40"/></svg><strong style="display:block;font-size:18px">That’s the day.</strong><a href="#wall" style="color:#00566a;font-weight:700">Back to now</a></div>
</div>'''


# ── Boards ─────────────────────────────────────────────────────────────────
def board(model, phone, title, open_sheet=None):
    base = dict(GS.PHONE if phone else GS.DESK)
    base['p'] = {'shelves': 's', 'stories': 't', 'swipe': 'w', 'scroll': 'r'}[model] + ('p' if phone else 'd')
    base['model'] = model
    base['noPanel'] = True
    if model == 'swipe':
        if phone:
            base.update(mode='deck', per=2, deckW=216, deckH=302, deckBox=(68, 148, 216, 302), btns=(176, 468), countY=118, trayBtn=(12, 62))
        else:
            slots = [[575, 300, 150, -2], [575, 560, 150, 2], [1160, 300, 150, 1.5], [1160, 560, 150, -1.5], [735, 690, 110, -3], [1040, 700, 110, 3]]
            base.update(mode='deck', per=6, cols=[0], deckW=280, deckH=392, deckBox=(800, 286, 280, 392), btns=(940, 744), countY=262, trayBtn=(560, 262), slots=slots)
        base['deck'] = deck(base)
        base['deckControls'] = deck_controls(base)
        base['panel'] = (10, 10, 10) if phone else (548, 34, 18)
    w, h = (390, 844) if phone else (1440, 1260)
    top = f'<img src="{AS("phone-top.jpg" if phone else "desk-top.jpg")}" alt="Events header and filters (as in the mock)" style="display:block;width:{w}px;height:{166 if phone else 144}px">'
    extras = ''
    if model == 'shelves':
        content = top + ('' if phone else '<div style="height:8px"></div>') + GS.card(base) + shelves(phone)
    elif model == 'stories':
        base['emptyHtml'] = SU.empty_state()
        base['mx'] = True
        if phone:
            # Everything stays on the wall; its posters page up and down
            # through the whole day, opening at now. One of the mock's
            # phone posters (142 x 270) per page.
            base.update(vertical=True, pageH=372, zoneLeft=192, zoneW=154, cols=[6], rows=[213], per=1, pw=142, ph=270, pool='day')
            content = SR.phone_header() + rings(phone) + GS.card(base) + '<p style="margin:8px 16px 90px;color:#6e6657;font-size:12px;text-align:center">Swipe the posters up for later, down for earlier</p>'
        else:
            content = SR.desk_header() + rings(phone) + GS.card(base) + '<div style="height:100px"></div>'
        pops = SU.popovers(phone)
        a = pops.index('<sc-if value="{{popDate}}"')
        b = pops.index('</sc-if>', a) + len('</sc-if>')
        pops = pops[:a] + SR.date_pop(phone) + SR.area_pop(phone) + pops[b:]
        t0 = pops.index('<sc-if value="{{hasToast}}"')
        pops = pops[:t0] + pops[pops.index('</sc-if>', t0) + len('</sc-if>'):]
        pops = pops.replace(SU.SAVED_THUMB, f'<button type="button" onClick="{H("it.open")}" aria-label="{H("it.alt")}" class="mx mx-art" style="position:relative;flex:none;width:60px;height:84px;padding:0;border:0;border-radius:3px;background:#e6ddcb;cursor:pointer;box-shadow:0 3px 8px -4px #0006">{MX.art_markup("it.art")}</button>')
        extras = pops + story_viewer(phone) + MX.lift_markup(LIFT_ZOOM[phone]) + MX.overlay_markup() + MX.toast_markup()
    elif model == 'swipe':
        content = top + chips('10px 12px 8px' if phone else '12px 32px 10px') + GS.card(base) + '<div style="height:100px"></div>'
        extras = tray(phone)
    else:
        cb = dict(W=390 if phone else 1440, p=base['p'], phone=phone, bandH=96 if phone else 130, bandTop=0, bandMargin='10px 12px 0' if phone else '16px 32px 0', wallMargin='10px 12px 0' if phone else '14px 32px 0')
        if phone:
            cb['W'] = 366
        else:
            cb['W'] = 1376
        content = top + ('' if phone else '<div style="height:8px"></div>') + GS.card(base) + chips('14px 12px 0' if phone else '18px 32px 0') + band(cb) + day_wall(cb)
    jc = {k: base[k] for k in ('phone', 'mode', 'W', 'SKY') if k in base}
    jc.update({k: base[k] for k in ('zoneW', 'bayW', 'px', 'pw', 'ph', 'rows', 'per', 'cols', 'pool', 'stepBays', 'spots', 'nowPill', 'vertical', 'pageH') if k in base})
    jc['col'] = {'pitch': 240, 'pw': 226, 'ph': 316, 'inset': 19}
    if model == 'stories':
        # The mock's posters: 300 x 420 on the wall and 364 x 560 on the
        # column at desktop sizes, scaled with zoom as the mock does; on a
        # phone, its slim posters at their own size.
        jc['col'] = {'pitch': 264, 'pw': 264, 'ph': 515, 'inset': 0} if phone else {'pitch': 264, 'pw': 205, 'ph': 316, 'inset': 29.5}
        jc['mxSizes'] = ({'wallW': 142, 'wallH': 270, 'colW': 264, 'colH': 515, 'liftZoom': LIFT_ZOOM[phone]} if phone
                         else {'wallZoom': round(190 / 300, 4), 'colZoom': round(316 / 560, 4), 'storyZoom': round(380 / 300, 4), 'liftZoom': LIFT_ZOOM[phone]})
    jc['model'] = model
    if model == 'shelves':
        jc.update(shelfN=10 if phone else 14, thumbW=124 if phone else 150, thumbH=172 if phone else 210)
    if model == 'stories':
        jc.update(storyW=300 if phone else 380, storyH=420 if phone else 532, feed=False, chrome=True)
        if open_sheet:
            jc['openSheet'] = open_sheet
    if model == 'swipe':
        jc.update(deckW=base['deckW'], deckH=base['deckH'], slots=base.get('slots', []))
    if model == 'scroll':
        # Fixed geometry, so the page knows which hour is under the band.
        top_h = 166 if phone else 152
        card_h = base['H']
        chips_h = 54 if phone else 58
        band_h = cb['bandH'] + (10 if phone else 16)
        jc.update(mW=cb['W'], bandTop=0, bandH=cb['bandH'], signH=58, padX=8 if phone else 36, mcols=2 if phone else 5,
                  colW=(366 - 16) // 2 if phone else (1376 - 72) // 5, cardW=150 if phone else 210, cardH=210 if phone else 294,
                  rowH=232 if phone else 318, stagger=26 if phone else 34, featW=240 if phone else 300, featH=336 if phone else 420, featRows=2 if phone else 2,
                  secStart=top_h + card_h + chips_h + band_h + (10 if phone else 14))
    props = {"skyAt": {"editor": "enum", "options": ["wall", "dawn", "morning", "now", "sunset", "dusk", "night"], "default": "wall", "section": "Review"},
             "weather": {"editor": "enum", "options": ["forecast", "clear", "partly", "cloudy", "showers", "rain", "mist"], "default": "forecast", "section": "Review"},
             "$preview": {"width": w, "height": h}}
    chrome = (f'<div aria-hidden="true" style="position:absolute;left:0;right:0;bottom:0;height:61px;background:url({AS("phone-bar.jpg")}) 0 0 / 390px 61px no-repeat, #fffdf8;z-index:50"></div>' if phone else '')
    if model == 'stories':
        chrome = SU.bottom_bar() if phone else ''
    scroll_attr = f' onScroll="{H("onScroll")}"' if model == 'scroll' else ''
    paste_css = '@media (prefers-reduced-motion: no-preference){.wp-paste{animation:wppaste .55s cubic-bezier(.22,1.4,.36,1)}@keyframes wppaste{from{transform:scale(1.25) rotate(-8deg);opacity:0}}}'
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Events · {title}</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&amp;family=Permanent+Marker&amp;display=swap" rel="stylesheet">
<style>{GS.css(base['W'], base['p'])}{paste_css}{MX.css(phone, w, h, AS) if model == 'stories' else ''}</style>
</helmet>
<div data-mx-root="" style="width:{w}px;height:{h}px;position:relative;overflow:hidden;background:#faf6ee;color:#211d15;font-family:Geist, system-ui, sans-serif;font-size:15px;line-height:1.4">
<div style="position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;scroll-behavior:smooth"{scroll_attr}>
{content}
</div>
{'' if model == 'stories' else details_strip(phone)}
{extras}
{chrome}
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{json.dumps(props)}'>
{logic(json.dumps(jc), real=(model == 'stories'))}
</script>
</body>
</html>
'''


MODELS = [
    ('shelves', 'Wall1', '1 · Shelves (Spotify, Netflix): the day in swipeable shelves, each under its own sky', 'Shelves'),
    ('stories', 'Wall2', '2 · Stories (Instagram) · chosen 12 Oct: hour stories on top; on phones the wall’s posters page up and down', 'Stories'),
    ('swipe', 'Wall3', '3 · Swipe (Tinder, Hinge): I’m in or not tonight; what you keep is pasted on your wall', 'Swipe'),
    ('scroll', 'Wall4', '4 · Scroll the day: the page is the wall; the sky follows you from morning to night', 'Scroll the day'),
]

if __name__ == '__main__':
    for model, stem, note, short in MODELS:
        for phone in (False, True):
            name = f'{stem}-{"phone" if phone else "desktop"}.dc.html'
            open(os.path.join(PROJ, name), 'w').write(board(model, phone, f'{short} · {"390" if phone else "1440"}'))
    idx_path = os.path.join(PROJ, 'canvas.json')
    idx = json.load(open(idx_path))
    # Out with the paging variants and the old D row; in with the four apps.
    for old in ['WallA-desktop.dc.html', 'WallA-phone.dc.html', 'WallB-desktop.dc.html', 'WallB-phone.dc.html', 'WallC-desktop.dc.html', 'WallC-phone.dc.html', 'WallD-desktop.dc.html', 'WallD-phone.dc.html']:
        idx['boards'].pop(old, None)
        if old in idx['order']:
            idx['order'].remove(old)
    for n in ('wallA', 'wallB', 'wallC', 'wallD'):
        idx['notes'].pop(n, None)
    y = 6100
    for model, stem, note, short in MODELS:
        idx['notes']['app' + stem[-1]] = {'x': 0, 'y': y - 240, 'text': note, 'kind': 'title1', 'maxW': 2380}
        for dev, x, w, h in (('desktop', 0, 1440, 1260), ('phone', 1520, 390, 844)):
            name = f'{stem}-{dev}.dc.html'
            idx['boards'][name] = {'x': x, 'y': y, 'w': w, 'h': h, 'title': f'{short} · {"1440" if dev == "desktop" else "390"} · living street · clickable', 'is_interactive': True}
            if name not in idx['order']:
                idx['order'].append(name)
        y += 1260 + 360
    idx['boards']['WallPoster.dc.html'].update({'x': 1990, 'y': 6100})
    # The phone's details sheet, open, beside the Stories phone board.
    open(os.path.join(PROJ, 'Wall2-phone-open.dc.html'), 'w').write(board('stories', True, 'Stories · 390 · details open', open_sheet='today-review-cinema'))
    idx['boards']['Wall2-phone-open.dc.html'] = {'x': 1990, 'y': 7720, 'w': 390, 'h': 844, 'title': 'Stories · 390 · details open (full screen) · clickable', 'is_interactive': True}
    if 'Wall2-phone-open.dc.html' not in idx['order']:
        idx['order'].append('Wall2-phone-open.dc.html')
    json.dump(idx, open(idx_path, 'w'), indent=2, ensure_ascii=False)
    print('ok')
