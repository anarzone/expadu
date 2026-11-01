#!/usr/bin/env python3
"""Clickable Events Wall options (A, B, C) for a 100-event day, phone and
desktop. Only the two sample events carry real facts; the other 98 are
labelled layout placeholders. Static boards from gen_wall.py are replaced."""
import json, os
import gen_wall as G

PROJ = G.PROJ

POSTER = '''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Wall poster</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;600;700;900&amp;family=Permanent+Marker&amp;display=swap" rel="stylesheet">
<style>body{margin:0}</style>
</helmet>
<div style="position: absolute; inset: 0; overflow: hidden; border-radius: 3px; container-type: size; font-family: Geist, system-ui, sans-serif; background: {{bg}}; color: {{ink}}; box-shadow: {{edge}}">
  <sc-if value="{{ph}}" hint-placeholder-val="{{ false }}">
    <div style="position: absolute; inset: 0; display: grid; place-items: center; text-align: center; padding: 8px">
      <span>
        <span style="display: inline-block; margin-bottom: 8px; padding: 3px 7px; border-radius: 5px; background: #211d15; color: #fffdf8; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums">{{hourLabel}}</span>
        <b style="display: block; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #4d4639">{{title}}</b>
        <small style="display: block; margin-top: 3px; font-size: 11px; color: #6e6657">100-event day · layout only</small>
      </span>
    </div>
  </sc-if>
  <sc-if value="{{real}}" hint-placeholder-val="{{ true }}">
    <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 5px; padding: 7cqw">
      <span style="display: flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 999px; background: #ffffff33; font-size: 10.5px; font-weight: 600">{{cat}}</span>
      <sc-if value="{{warn}}" hint-placeholder-val="{{ false }}">
        <span style="display: flex; align-items: center; height: 20px; padding: 0 7px; border-radius: 999px; background: #fff1c7; color: #7a4b00; font-size: 10px; font-weight: 600; white-space: nowrap">Rain likely · sample</span>
      </sc-if>
      <span style="flex: 1 1 0; min-height: 0"></span>
      <span style="padding: 1px 6px; background: #ffe36e; color: #211d15; font-family: 'Permanent Marker', cursive; font-size: 10.5px; transform: rotate(-3deg)">Tonight · 30 Sept</span>
      <h4 style="margin: 2px 0 0; font-size: clamp(12px, 11cqw, 30px); font-weight: 900; line-height: 0.95; letter-spacing: -0.02em; text-transform: uppercase">{{title}}</h4>
      <span style="flex: 1 1 0; min-height: 0"></span>
      <div style="font-size: 10.5px; line-height: 1.3">
        <div style="font-size: clamp(18px, 15cqw, 46px); font-weight: 300; line-height: 1; color: transparent; -webkit-text-stroke: 1.2px {{ink}}">{{time}}</div>
        <div>{{until}} · {{place}}</div>
        <div>{{price}}</div>
      </div>
    </div>
  </sc-if>
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{"p":{"editor":null},"$preview":{"width":200,"height":280}}'>
class Component extends DCLogic {
  renderVals() {
    const p = this.props.p || { ph: false, title: 'Street culture night', time: '18:30', until: 'until 21:00', place: 'Rheinauhafen', price: 'Price not listed', cat: 'Culture', bg: 'linear-gradient(160deg,#5b3bd6,#3a22a8)', ink: '#ffe36e', warn: false };
    const ph = !!p.ph;
    return Object.assign({}, p, {
      ph, real: !ph, warn: !!p.warn,
      bg: ph ? 'repeating-linear-gradient(135deg,#ece4d3 0 10px,#e4dac6 10px 20px)' : p.bg,
      ink: ph ? '#4d4639' : p.ink,
      edge: ph ? 'inset 0 0 0 1.5px #c9bba0' : 'none',
    });
  }
}
</script>
</body>
</html>
'''

LOGIC = r'''
class Component extends DCLogic {
  cfg() { return __CFG__; }
  events() {
    if (this._ev) return this._ev;
    const COUNTS = { 8: 2, 9: 3, 10: 6, 11: 5, 12: 4, 13: 6, 14: 5, 15: 7, 16: 8, 17: 9, 18: 11, 19: 10, 20: 9, 21: 7, 22: 5, 23: 3 };
    const two = (h) => (h < 10 ? '0' : '') + h;
    const ev = [];
    let n = 0;
    for (let h = 8; h <= 23; h++) {
      for (let i = 0; i < COUNTS[h]; i++) {
        n++;
        let e = { n, h, ph: true, title: 'Placeholder ' + n, time: two(h) + ':··', hourLabel: two(h) + ':00 hour' };
        // The two real sample events start their hours (earliest start first).
        if (h === 18 && i === 0) e = { n, h, ph: false, title: 'Street culture night', time: '18:30', until: 'until 21:00', place: 'Rheinauhafen', price: 'Price not listed', cat: 'Culture', bg: 'linear-gradient(160deg,#5b3bd6,#3a22a8)', ink: '#ffe36e', warn: false };
        if (h === 20 && i === 0) e = { n, h, ph: false, title: 'Cinema under the stars', time: '20:00', until: 'until 22:00', place: 'Stadtgarten', price: 'Price not listed', cat: 'Culture', bg: 'radial-gradient(circle at 70% 26%,#f7efc8 0 13%,#1d1745 14%)', ink: '#fffdf8', warn: true };
        ev.push(e);
      }
    }
    this._ev = { ev, COUNTS, two };
    return this._ev;
  }
  sky(hours) {
    const SKY = { 8: '#f6c79a', 9: '#bfe0f0', 10: '#9fd3ef', 11: '#86c8ec', 12: '#7cc3ea', 13: '#7cc3ea', 14: '#84c6ea', 15: '#93cbe6', 16: '#b9cfd6', 17: '#efc98f', 18: '#f0a86e', 19: '#d97757', 20: '#7b4f8f', 21: '#3f3a78', 22: '#26285a', 23: '#1c2344' };
    const hs = hours.length > 1 ? hours : [hours[0], hours[0]];
    return 'linear-gradient(90deg,' + hs.map((h, i) => SKY[h] + ' ' + Math.round((i / (hs.length - 1)) * 100) + '%').join(',') + ')';
  }
  // Drag the wall: follows the pointer, then settles on the nearest stop.
  down(e) {
    this._d = { x: e.clientX, moved: false, id: e.pointerId, el: e.currentTarget };
  }
  move(e) {
    const d = this._d;
    if (!d) return;
    const dx = e.clientX - d.x;
    if (!d.moved && Math.abs(dx) < 6) return;
    if (!d.moved) {
      d.moved = true;
      try { d.el.setPointerCapture(d.id); } catch (err) { /* fine */ }
    }
    this.setState({ drag: dx });
  }
  up() {
    const d = this._d;
    this._d = null;
    if (!d || !d.moved) return;
    const dx = (this.state && this.state.drag) || 0;
    this.settle(dx);
  }
  settle(dx) {
    const c = this.cfg();
    const s = this.state || {};
    if (c.mode === 'walk') {
      const stops = this.stops();
      const at = this.offset() - dx;
      let best = stops[0];
      stops.forEach((x) => { if (Math.abs(x - at) < Math.abs(best - at)) best = x; });
      // A quick flick moves at least one stop.
      if (best === this.offset() && Math.abs(dx) > 40) {
        const i = stops.indexOf(best) + (dx < 0 ? 1 : -1);
        best = stops[Math.max(0, Math.min(stops.length - 1, i))];
      }
      this.setState({ drag: 0, offset: best });
    } else {
      const pages = this.pages();
      let page = s.page || 0;
      if (dx < -50) page = Math.min(pages - 1, page + 1);
      if (dx > 50) page = Math.max(0, page - 1);
      this.setState({ drag: 0, page });
    }
  }
  // Walk mode: the stops are the left edges of the wall's pieces.
  items() {
    const c = this.cfg();
    const { ev, two } = this.events();
    const list = [];
    if (c.phone) {
      list.push({ kind: 'earlier', w: 150 });
      list.push({ kind: 'column', w: 190 });
      for (let h = 13; h <= 23; h++) list.push({ kind: 'bay', h, w: 140 });
    } else {
      for (let h = 8; h <= 23; h++) list.push({ kind: 'bay', h, w: 209 });
    }
    let x = 0;
    list.forEach((it) => { it.x = x; x += it.w; });
    this._total = x;
    return list;
  }
  stops() {
    const c = this.cfg();
    const its = this.items();
    const max = Math.max(0, this._total - c.view);
    const out = [];
    its.forEach((it) => { if (it.x <= max) out.push(it.x); });
    if (out[out.length - 1] !== max) out.push(max);
    return out;
  }
  offset() {
    const s = this.state || {};
    if (typeof s.offset === 'number') return s.offset;
    const its = this.items();
    // Open at now (13:10): the 13:00 hour, or the column on phones.
    const start = this.cfg().phone ? its.find((i) => i.kind === 'column') : its.find((i) => i.h === 13);
    return start.x;
  }
  pages() {
    const c = this.cfg();
    return Math.ceil(this.pool().length / c.per);
  }
  pool() {
    const c = this.cfg();
    const { ev } = this.events();
    return c.option === 'C' ? ev.filter((e) => e.h >= 13).slice(0, 6) : ev;
  }
  renderVals() {
    const c = this.cfg();
    const s = Object.assign({ drag: 0, page: 0, open: {}, focus: 0, turn: 0 }, this.state || {});
    const { ev, COUNTS, two } = this.events();
    const go = (patch) => () => this.setState(patch);
    const openHour = (h) => () => {
      const open = Object.assign({}, s.open);
      open[h] = true;
      this.setState({ open, focus: h });
    };
    let visibleHours = [];
    let bays = [], grid = [], items = [], stripStyle = '', gridStyle = '';
    if (c.mode === 'walk') {
      const its = this.items();
      const off = this.offset();
      const x = off - s.drag;
      stripStyle = 'transform: translateX(' + (-x) + 'px); transition: ' + (s.drag ? 'none' : 'transform 280ms cubic-bezier(0.22,1,0.36,1)') + ';';
      its.forEach((it) => {
        const left = it.x - x;
        const shown = left + it.w > 20 && left < c.view - 20;
        if (it.kind === 'bay' && shown) visibleHours.push(it.h);
        if (it.kind !== 'bay') { items.push(Object.assign({ isEarlier: it.kind === 'earlier', isColumn: it.kind === 'column', isBay: false, posters: [] }, it)); return; }
        const here = ev.filter((e) => e.h === it.h);
        const spots = c.phone ? 1 : 2;
        const posters = here.slice(0, spots).map((e, i) => Object.assign({}, e, {
          x: c.phone ? 10 : (i ? 24 : 18), y: c.phone ? 42 : (i ? 232 : 46), w: c.phone ? 120 : (i ? 160 : 170), h: c.phone ? 178 : (i ? 165 : 180),
          rot: [-1.5, 1, -0.5, 1.5][(e.n + i) % 4],
        }));
        const more = here.length - posters.length;
        items.push(Object.assign({}, it, {
          isBay: true, isEarlier: false, isColumn: false, label: two(it.h) + ':00', isNow: it.h === 13, past: it.h < 13,
          op: it.h < 13 ? 0.62 : 1, posters, hasMore: more > 0,
          moreLabel: '+' + more + ' more at ' + two(it.h) + ':00', moreHref: '#g' + it.h, more: openHour(it.h),
          moreTop: c.phone ? 226 : 404,
        }));
      });
      bays = items;
    } else {
      const pool = this.pool();
      const page = Math.min(s.page, this.pages() - 1);
      const slice = pool.slice(page * c.per, page * c.per + c.per);
      visibleHours = Array.from(new Set(slice.map((e) => e.h)));
      gridStyle = 'transform: translateX(' + s.drag + 'px); transition: ' + (s.drag ? 'none' : 'transform 220ms ease') + ';';
      grid = slice.map((e, i) => {
        const col = c.phone ? 0 : i % 3, row = c.phone ? i : Math.floor(i / 3);
        return Object.assign({}, e, {
          x: c.phone ? 6 + (i % 2) * 4 : col * 300, y: c.phone ? 6 + row * 176 : row * 205, w: c.phone ? 136 : 220, h: c.phone ? 160 : 188,
          rot: [-1.5, 1, -0.5, 1.5][(e.n + i) % 4], hourLabel: e.ph ? two(e.h) + ':00 hour' : e.hourLabel,
        });
      });
    }
    if (!visibleHours.length) visibleHours = [13];
    visibleHours.sort((a, b) => a - b);
    const night = visibleHours[visibleHours.length - 1] >= 21;
    // The day under the wall: one bar per hour.
    const ruler = [];
    for (let h = 8; h <= 23; h++) {
      const on = visibleHours.indexOf(h) >= 0;
      ruler.push({
        label: two(h), bar: Math.max(4, COUNTS[h] * (c.phone ? 2 : 3)),
        barStyle: 'background:' + (on ? '#00768b' : '#d9cdb5'),
        labelStyle: on ? 'color:#00566a;font-weight:700' : 'color:#6e6657',
        pick: () => {
          if (c.mode === 'walk') {
            const its = this.items();
            const target = c.phone && h < 13 ? its[0] : its.find((i) => i.h === h);
            const stops = this.stops();
            const x = Math.min(target.x, stops[stops.length - 1]);
            this.setState({ offset: x, drag: 0 });
          } else {
            const idx = this.pool().findIndex((e) => e.h === h);
            if (idx >= 0) this.setState({ page: Math.floor(idx / c.per) });
          }
        },
      });
    }
    // Pages: a window of dots around the current one.
    const pages = c.mode === 'page' ? this.pages() : 0;
    const page = Math.min(s.page, Math.max(0, pages - 1));
    const dots = [];
    for (let i = Math.max(0, Math.min(page - 3, pages - 7)); i < Math.min(pages, Math.max(0, Math.min(page - 3, pages - 7)) + 7); i++)
      dots.push({ style: i === page ? 'width:22px;background:#211d15' : 'width:8px;background:#cdbfa3', pick: go({ page: i }), label: 'Page ' + (i + 1) });
    // The column turns through what's coming up.
    const coming = [ev.find((e) => e.title === 'Street culture night'), ev.find((e) => e.title === 'Cinema under the stars'), ev.find((e) => e.h === 19), ev.find((e) => e.h === 21)];
    const colPoster = coming[s.turn % 4];
    // The list: every hour, two rows until opened.
    const onWall = c.option === 'C' ? this.pool().map((e) => e.n) : [];
    const groups = [];
    for (let h = 8; h <= 23; h++) {
      const all = ev.filter((e) => e.h === h && onWall.indexOf(e.n) < 0);
      if (!all.length) continue;
      const open = !!s.open[h];
      const rows = (open ? all : all.slice(0, 2)).map((e) => Object.assign({}, e, { real: !e.ph, sk: 40 + ((e.n * 37) % 45) + '%', chip: e.cat || 'Category' }));
      const toggle = () => {
        const o = Object.assign({}, s.open);
        o[h] = !open;
        this.setState({ open: o });
      };
      groups.push({
        id: 'g' + h, head: two(h) + ':00 · ' + all.length + (all.length === 1 ? ' event' : ' events'), rows,
        hasToggle: all.length > 2, toggleLabel: open ? 'Show fewer' : 'Show ' + (all.length - 2) + ' more at ' + two(h) + ':00', toggle,
        headStyle: s.focus === h ? 'background:#def5f8;border-radius:10px;padding-left:10px;padding-right:10px' : '', focused: s.focus === h,
      });
    }
    const atNow = c.mode !== 'walk' || this.offset() === (c.phone ? this.items()[1].x : this.items().find((i) => i.h === 13).x);
    return {
      bays, grid, stripStyle, gridStyle, ruler, dots, groups, colPoster,
      skyBg: this.sky(visibleHours), cityFill: night ? '#0b0f22' : '#26314d',
      down: (e) => this.down(e), move: (e) => this.move(e), up: () => this.up(),
      earlier: () => this.step(-1), later: () => this.step(1),
      backToNow: go({ offset: undefined, drag: 0 }), notAtNow: !atNow,
      prevPage: go({ page: Math.max(0, page - 1) }), nextPage: go({ page: Math.min(pages - 1, page + 1) }),
      pageLabel: c.option === 'C' ? 'Next 6 from 13:10' : 'Page ' + (page + 1) + ' of ' + pages,
      turn: go({ turn: s.turn + 1 }), turnLabel: 'Coming up · ' + ((s.turn % 4) + 1) + ' of 4 · tap to turn',
      listTitle: c.option === 'C' ? 'The rest of today · 94 events' : 'All 100 events today',
    };
  }
  step(dir) {
    const stops = this.stops();
    const at = this.offset();
    let i = stops.indexOf(at);
    if (i < 0) i = 0;
    const next = stops[Math.max(0, Math.min(stops.length - 1, i + dir * (this.cfg().phone ? 1 : 2)))];
    this.setState({ offset: next, drag: 0 });
  }
}
'''


def sky_svg(w, h):
    """The silhouette, filled from renderVals (darker at night)."""
    html = G.sky(w, h, G.DAY)
    svg = html[html.index('<svg'):html.index('</svg>') + 6]
    return svg.replace('fill="#26314d"', 'fill="{{cityFill}}"')


def poster_slot(prefix):
    return (f'<div style="position: absolute; left: {{{{{prefix}.x}}}}px; top: {{{{{prefix}.y}}}}px; width: {{{{{prefix}.w}}}}px; height: {{{{{prefix}.h}}}}px; transform: rotate({{{{{prefix}.rot}}}}deg); box-shadow: 0 8px 18px -10px #0006">'
            f'<dc-import name="WallPoster" p="{{{{{prefix}}}}}" hint-size="100%,100%"></dc-import></div>')


def column_block(w):
    ph = round(w * 1.42)
    return f'''<div style="width: {w + 40}px">
  <div style="height: {round(w * .12)}px; margin-bottom: -2px; border-radius: 50% 50% 8px 8px / 100% 100% 8px 8px; background: #2f4f40"></div>
  <div style="height: 8px; margin: 0 6px; background: #c9a94a"></div>
  <button type="button" onClick="{{{{turn}}}}" aria-label="Turn the column" style="display: block; position: relative; width: {w}px; height: {ph}px; margin: 0 20px; padding: 0; border: 0; background: #3a2a9c; cursor: pointer"><dc-import name="WallPoster" p="{{{{colPoster}}}}" hint-size="100%,100%"></dc-import></button>
  <div style="height: 14px; margin: 0 12px; background: #2f4f40; border-radius: 0 0 4px 4px"></div>
  <div style="margin-top: 8px; text-align: center; color: #6e6657; font-size: 11.5px">{{{{turnLabel}}}}</div>
</div>'''


def list_html(phone):
    pad = '0 16px 120px' if phone else '0 32px 60px'
    width = '' if phone else 'max-width: 900px;'
    return f'''<section id="list" style="padding: {pad}; {width}">
  <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 20px 0 8px">
    <h3 style="margin: 0; font-size: {20 if phone else 24}px; letter-spacing: -0.5px">{{{{listTitle}}}}</h3>
    <a href="#wall" style="color: #00566a; font-size: 13px; font-weight: 600; text-decoration: none">Back to the wall</a>
  </div>
  <sc-for list="{{{{groups}}}}" as="g" hint-placeholder-count="4">
    <div id="{{{{g.id}}}}" style="border-top: 1px solid #e6ddcb; scroll-margin-top: 12px">
      <h5 style="margin: 0; padding: 10px 0 6px; font-size: 13px; font-weight: 700; color: #4d4639; font-variant-numeric: tabular-nums; {{{{g.headStyle}}}}">{{{{g.head}}}}</h5>
      <sc-for list="{{{{g.rows}}}}" as="r" hint-placeholder-count="2">
        <div style="display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; gap: 10px; align-items: center; min-height: 52px; padding: 6px 0; border-top: 1px solid #efe7d6">
          <span style="font-weight: 700; font-variant-numeric: tabular-nums">{{{{r.time}}}}</span>
          <sc-if value="{{{{r.real}}}}" hint-placeholder-val="{{{{ false }}}}"><span><strong>{{{{r.title}}}}</strong><span style="display: block; color: #6e6657; font-size: 12.5px">{{{{r.place}}}} · {{{{r.until}}}} · {{{{r.price}}}}</span></span></sc-if>
          <sc-if value="{{{{r.ph}}}}" hint-placeholder-val="{{{{ true }}}}"><span><span style="display: block; height: 10px; width: {{{{r.sk}}}}; border-radius: 5px; background: #e6ddcb"></span><span style="display: block; margin-top: 4px; color: #6e6657; font-size: 12.5px">{{{{r.title}}}} · layout only</span></span></sc-if>
          <span style="display: inline-flex; align-items: center; height: 26px; padding: 0 9px; border-radius: 999px; background: #efe7d6; font-size: 12px; font-weight: 600; color: #4d4639">{{{{r.chip}}}}</span>
        </div>
      </sc-for>
      <sc-if value="{{{{g.hasToggle}}}}" hint-placeholder-val="{{{{ false }}}}">
        <button type="button" onClick="{{{{g.toggle}}}}" style="min-height: 44px; padding: 0; border: 0; background: none; color: #00566a; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer">{{{{g.toggleLabel}}}}</button>
      </sc-if>
    </div>
  </sc-for>
</section>'''


def ruler_html(phone):
    return f'''<div role="group" aria-label="The day, one bar per hour" style="display: flex; align-items: flex-end; gap: {2 if phone else 3}px">
  <sc-for list="{{{{ruler}}}}" as="r" hint-placeholder-count="16">
    <button type="button" onClick="{{{{r.pick}}}}" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; min-height: 44px; justify-content: flex-end; padding: 0; border: 0; background: none; font: inherit; font-size: {10.5 if phone else 11}px; font-variant-numeric: tabular-nums; cursor: pointer; {{{{r.labelStyle}}}}">
      <i style="display: block; width: 100%; height: {{{{r.bar}}}}px; border-radius: 3px 3px 0 0; {{{{r.barStyle}}}}"></i>{{{{r.label}}}}
    </button>
  </sc-for>
</div>'''


ARROW = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{d}"/></svg>'
LEFT = ARROW.format(d='m15 5.5-6.5 6.5 6.5 6.5')
RIGHT = ARROW.format(d='m9 5.5 6.5 6.5L9 18.5')
ARROW_BTN = 'position: absolute; display: grid; place-items: center; width: 48px; height: 48px; border-radius: 50%; background: #fffdf8; border: 1px solid #e6ddcb; box-shadow: 0 4px 12px -6px #0005; color: #211d15; cursor: pointer; z-index: 4'


def wall_desktop(option):
    W, H = 1376, 640
    if option == 'A':
        inner = f'''<div style="position: absolute; left: 330px; right: 0; top: 158px; bottom: 0; overflow: hidden">
      <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 4000px; {{{{stripStyle}}}}">
        <sc-for list="{{{{bays}}}}" as="b" hint-placeholder-count="5">
          <div style="position: absolute; top: 0; bottom: 60px; left: {{{{b.x}}}}px; width: {{{{b.w}}}}px; border-left: 1px dashed #00000022; opacity: {{{{b.op}}}}">
            <span style="position: absolute; left: 12px; top: 12px; display: flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 6px; background: #211d15; color: #fffdf8; font-size: 12.5px; font-weight: 700">{{{{b.label}}}}</span>
            <sc-if value="{{{{b.isNow}}}}" hint-placeholder-val="{{{{ false }}}}"><span style="position: absolute; left: 82px; top: 12px; display: flex; align-items: center; height: 26px; padding: 0 9px; border-radius: 999px; background: #c23b0e; color: #fff; font-size: 12px; font-weight: 700">Now 13:10</span></sc-if>
            <sc-for list="{{{{b.posters}}}}" as="p" hint-placeholder-count="2">{poster_slot('p')}</sc-for>
            <sc-if value="{{{{b.hasMore}}}}" hint-placeholder-val="{{{{ false }}}}"><a href="{{{{b.moreHref}}}}" onClick="{{{{b.more}}}}" style="position: absolute; left: 30px; top: {{{{b.moreTop}}}}px; padding: 7px 10px; background: #fff6c9; color: #211d15; font-size: 12.5px; font-weight: 700; text-decoration: none; transform: rotate(-2deg); box-shadow: 0 3px 8px -4px #0005; z-index: 2">{{{{b.moreLabel}}}}</a></sc-if>
          </div>
        </sc-for>
      </div>
    </div>
    <button type="button" aria-label="Earlier" onClick="{{{{earlier}}}}" style="{ARROW_BTN}; left: 300px; top: 380px">{LEFT}</button>
    <button type="button" aria-label="Later" onClick="{{{{later}}}}" style="{ARROW_BTN}; right: 20px; top: 380px">{RIGHT}</button>
    <sc-if value="{{{{notAtNow}}}}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{{{{backToNow}}}}" style="position: absolute; left: 350px; top: 590px; height: 36px; padding: 0 14px; border: 0; border-radius: 999px; background: #c23b0e; color: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; z-index: 4">Back to now · 13:10</button></sc-if>'''
    else:
        inner = f'''<div style="position: absolute; left: 360px; top: 184px; width: 900px; height: 400px; {{{{gridStyle}}}}">
      <sc-for list="{{{{grid}}}}" as="p" hint-placeholder-count="6">{poster_slot('p')}</sc-for>
    </div>
    <div style="position: absolute; left: 360px; bottom: 18px; display: flex; align-items: center; gap: 14px; z-index: 3">
      {'<div style="display: flex; align-items: center; gap: 2px"><sc-for list="{{dots}}" as="d" hint-placeholder-count="7"><button type="button" aria-label="{{d.label}}" onClick="{{d.pick}}" style="display: grid; place-items: center; width: 26px; height: 44px; padding: 0; border: 0; background: none; cursor: pointer"><i style="display: block; height: 8px; border-radius: 4px; {{d.style}}"></i></button></sc-for></div>' if option == 'B' else ''}
      <strong style="font-size: 13px">{{{{pageLabel}}}}</strong>
      <span style="color: #4d4639; font-size: 12.5px">{'6 posters a page, in time order' if option == 'B' else '· 94 more in the list below'}</span>
    </div>
    {f'<button type="button" aria-label="Previous page" onClick="{{{{prevPage}}}}" style="{ARROW_BTN}; left: 300px; top: 380px">{LEFT}</button><button type="button" aria-label="Next page" onClick="{{{{nextPage}}}}" style="{ARROW_BTN}; right: 20px; top: 380px">{RIGHT}</button>' if option == 'B' else ''}'''
    return f'''<div id="wall" style="position: relative; width: {W}px; height: {H}px; margin: 0 32px; overflow: hidden; border-radius: 28px; background: #e9e1d1; box-shadow: inset 0 0 0 1px #d8ccb4; touch-action: pan-y; user-select: none; scroll-margin-top: 12px" onPointerDown="{{{{down}}}}" onPointerMove="{{{{move}}}}" onPointerUp="{{{{up}}}}" onPointerCancel="{{{{up}}}}">
    <div style="position: absolute; left: 0; right: 0; top: 0; height: 150px; background: {{{{skyBg}}}}; transition: background 300ms">{sky_svg(W, 150)}</div>
    <div style="position: absolute; left: 0; right: 0; top: 150px; height: 8px; background: #cdbfa3"></div>
    <div style="position: absolute; left: 0; right: 0; bottom: 0; height: 60px; background: #cfc3ac"></div>
    <span style="position: absolute; right: 28px; bottom: 18px; font-family: 'Courier New', monospace; font-weight: 700; letter-spacing: .3em; color: #00000033; font-size: 20px">PLAKATIEREN VERBOTEN</span>
    {inner}
    <div style="position: absolute; left: 0; top: 158px; bottom: 60px; width: 330px; background: linear-gradient(90deg, #e9e1d1 88%, #e9e1d100); z-index: 3"></div>
    <div style="position: absolute; left: 30px; top: 176px; z-index: 3">{column_block(230)}</div>
    <div style="position: absolute; right: 24px; top: 18px; padding: 8px 12px; border-radius: 12px; background: #fffdf8d9; font-size: 12.5px; z-index: 3"><strong>21° Sunny</strong><br>13:10 · Sample weather</div>
  </div>'''


def wall_phone(option):
    W, H = 366, 520
    if option == 'A':
        strip = f'''<div style="position: absolute; left: 0; right: 0; top: 98px; bottom: 0; overflow: hidden">
      <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 2400px; {{{{stripStyle}}}}">
        <sc-for list="{{{{bays}}}}" as="b" hint-placeholder-count="3">
          <div style="position: absolute; top: 0; bottom: 110px; left: {{{{b.x}}}}px; width: {{{{b.w}}}}px">
            <sc-if value="{{{{b.isEarlier}}}}" hint-placeholder-val="{{{{ false }}}}">
              <a href="#g8" style="position: absolute; left: 10px; right: 10px; top: 30px; bottom: 30px; display: grid; place-items: center; padding: 10px; border-radius: 6px; background: #fffdf8b3; box-shadow: inset 0 0 0 1px #d8ccb4; color: #211d15; text-align: center; text-decoration: none; font-size: 13px"><span><strong style="display: block; font-size: 15px">Earlier today</strong>08:00–12:00 · 20 events<span style="display: block; margin-top: 8px; color: #00566a; font-weight: 600">See them in the list</span></span></a>
            </sc-if>
            <sc-if value="{{{{b.isColumn}}}}" hint-placeholder-val="{{{{ false }}}}"><div style="position: absolute; left: 0; top: 8px; transform: scale(0.86); transform-origin: 0 0">{column_block(150)}</div></sc-if>
            <sc-if value="{{{{b.isBay}}}}" hint-placeholder-val="{{{{ true }}}}">
              <div style="position: absolute; inset: 0; border-left: 1px dashed #00000022">
                <span style="position: absolute; left: 10px; top: 8px; display: flex; align-items: center; height: 26px; padding: 0 9px; border-radius: 6px; background: #211d15; color: #fffdf8; font-size: 12.5px; font-weight: 700">{{{{b.label}}}}</span>
                <sc-for list="{{{{b.posters}}}}" as="p" hint-placeholder-count="1">{poster_slot('p')}</sc-for>
                <sc-if value="{{{{b.hasMore}}}}" hint-placeholder-val="{{{{ false }}}}"><a href="{{{{b.moreHref}}}}" onClick="{{{{b.more}}}}" style="position: absolute; left: 10px; top: {{{{b.moreTop}}}}px; padding: 7px 9px; background: #fff6c9; color: #211d15; font-size: 12px; font-weight: 700; text-decoration: none; transform: rotate(-2deg); box-shadow: 0 3px 8px -4px #0005; z-index: 2; white-space: nowrap">{{{{b.moreLabel}}}}</a></sc-if>
                <sc-if value="{{{{b.isNow}}}}" hint-placeholder-val="{{{{ false }}}}"><span style="position: absolute; left: 10px; top: 268px; display: flex; align-items: center; height: 26px; padding: 0 9px; border-radius: 999px; background: #c23b0e; color: #fff; font-size: 12px; font-weight: 700">Now 13:10</span></sc-if>
              </div>
            </sc-if>
          </div>
        </sc-for>
      </div>
    </div>
    <sc-if value="{{{{notAtNow}}}}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{{{{backToNow}}}}" style="position: absolute; right: 10px; top: 10px; height: 34px; padding: 0 12px; border: 0; border-radius: 999px; background: #c23b0e; color: #fff; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; z-index: 4">Back to now</button></sc-if>
    <div style="position: absolute; left: 12px; right: 12px; bottom: 56px; z-index: 3">{ruler_html(True)}</div>'''
    else:
        strip = f'''<div style="position: absolute; left: 10px; top: 108px; transform: scale(0.86); transform-origin: 0 0; z-index: 3">{column_block(150)}</div>
    <div style="position: absolute; left: 206px; top: 106px; width: 160px; height: 360px; {{{{gridStyle}}}}">
      <sc-for list="{{{{grid}}}}" as="p" hint-placeholder-count="2">{poster_slot('p')}</sc-for>
    </div>
    <div style="position: absolute; left: 0; right: 0; bottom: 6px; display: flex; justify-content: center; align-items: center; gap: 4px; z-index: 3">
      {'<sc-for list="{{dots}}" as="d" hint-placeholder-count="7"><button type="button" aria-label="{{d.label}}" onClick="{{d.pick}}" style="display: grid; place-items: center; width: 24px; height: 44px; padding: 0; border: 0; background: none; cursor: pointer"><i style="display: block; height: 8px; border-radius: 4px; {{d.style}}"></i></button></sc-for>' if option == 'B' else ''}
      <strong style="font-size: 12.5px; margin-left: 6px">{{{{pageLabel}}}}</strong>
      {'<span style="font-size: 12px; color: #4d4639">· swipe for more</span>' if option == 'C' else ''}
    </div>'''
    return f'''<div id="wall" style="position: relative; width: {W}px; height: {H}px; margin: 4px 12px 0; overflow: hidden; border-radius: 24px; background: #e9e1d1; box-shadow: inset 0 0 0 1px #d8ccb4; touch-action: pan-y; user-select: none; scroll-margin-top: 8px" onPointerDown="{{{{down}}}}" onPointerMove="{{{{move}}}}" onPointerUp="{{{{up}}}}" onPointerCancel="{{{{up}}}}">
    <div style="position: absolute; left: 0; right: 0; top: 0; height: 92px; background: {{{{skyBg}}}}">{sky_svg(W, 92)}</div>
    <div style="position: absolute; left: 0; right: 0; top: 92px; height: 6px; background: #cdbfa3"></div>
    <div style="position: absolute; left: 0; right: 0; bottom: 0; height: 50px; background: #cfc3ac"></div>
    {strip}
  </div>
  <p style="margin: 8px 16px 0; color: #6e6657; font-size: 12px; text-align: center">{'Drag the wall through the day · morning ← → night' if option == 'A' else 'Swipe the posters, or tap the dots' if option == 'B' else 'Swipe for the next posters'}</p>'''


def board(option, phone):
    cfg = {'option': option, 'phone': phone,
           'mode': 'walk' if option == 'A' else 'page',
           'view': 366 if phone else 1046,
           'per': 2 if phone else 6}
    w, h = (390, 844) if phone else (1440, 900)
    if phone:
        content = G.phone_top() + G.phone_filters() + wall_phone(option) + list_html(True)
        chrome = G.tabbar()
    else:
        under = '' if option == 'C' else f'<div style="margin: 14px 32px 0">{ruler_html(False)}<div style="margin-top: 4px; color: #6e6657; font-size: 12px">The whole day: one bar per hour; the hours on the wall in teal. Tap an hour to go there. The bars show the placeholder day.</div></div>'
        content = G.desk_top() + G.desk_filters() + wall_desktop(option) + under + list_html(False)
        chrome = ''
    logic = LOGIC.replace('__CFG__', json.dumps(cfg))
    title = f'Events · {option} · {"390" if phone else "1440"} · clickable'
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{title}</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&amp;family=Permanent+Marker&amp;display=swap" rel="stylesheet">
<style>body{{margin:0}}{G.CSS}</style>
</helmet>
<div style="width: {w}px; height: {h}px; position: relative; overflow: hidden; background: #faf6ee; color: #211d15; font-family: Geist, system-ui, sans-serif; font-size: 15px; line-height: 1.4">
<div style="position: absolute; inset: 0; overflow-y: auto; overflow-x: hidden; scroll-behavior: smooth">
{content}
</div>
{chrome}
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{{"$preview":{{"width":{w},"height":{h}}}}}'>
{logic}
</script>
</body>
</html>
'''


BOARDS = [
    ('WallA-desktop.dc.html', 'A', False), ('WallA-phone.dc.html', 'A', True),
    ('WallB-desktop.dc.html', 'B', False), ('WallB-phone.dc.html', 'B', True),
    ('WallC-desktop.dc.html', 'C', False), ('WallC-phone.dc.html', 'C', True),
]
TITLES = {
    'WallA-desktop.dc.html': 'A · fixed wall you walk along · 1440 · clickable',
    'WallA-phone.dc.html': 'A · fixed wall · 390 · clickable',
    'WallB-desktop.dc.html': 'B · paged wall · 1440 · clickable',
    'WallB-phone.dc.html': 'B · paged wall · 390 · clickable',
    'WallC-desktop.dc.html': 'C · highlights + list · 1440 · clickable',
    'WallC-phone.dc.html': 'C · highlights + list · 390 · clickable',
}

if __name__ == '__main__':
    open(os.path.join(PROJ, 'WallPoster.dc.html'), 'w').write(POSTER)
    for name, option, phone in BOARDS:
        open(os.path.join(PROJ, name), 'w').write(board(option, phone))
    idx_path = os.path.join(PROJ, 'canvas.json')
    idx = json.load(open(idx_path))
    # Rows: A, B, C; desktop 1440×900 then phone 390×844.
    y0 = 6100
    for i, opt in enumerate('ABC'):
        y = y0 + i * (900 + 120 + 240)
        idx['boards'][f'Wall{opt}-desktop.dc.html'] = {'x': 0, 'y': y, 'w': 1440, 'h': 900, 'title': TITLES[f'Wall{opt}-desktop.dc.html'], 'is_interactive': True}
        idx['boards'][f'Wall{opt}-phone.dc.html'] = {'x': 1520, 'y': y, 'w': 390, 'h': 844, 'title': TITLES[f'Wall{opt}-phone.dc.html'], 'is_interactive': True}
        note = {'A': 'wallA', 'B': 'wallB', 'C': 'wallC'}[opt]
        idx['notes'][note]['y'] = y - 240
    # The list now lives inside A's phone board; the shared poster sits beside A.
    idx['boards'].pop('WallA-list.dc.html', None)
    idx['order'] = [o for o in idx['order'] if o != 'WallA-list.dc.html']
    idx['boards']['WallPoster.dc.html'] = {'x': 1990, 'y': y0, 'w': 200, 'h': 280, 'title': 'Shared poster (used by every option)'}
    if 'WallPoster.dc.html' not in idx['order']:
        idx['order'].append('WallPoster.dc.html')
    json.dump(idx, open(idx_path, 'w'), indent=2, ensure_ascii=False)
    print('ok')
