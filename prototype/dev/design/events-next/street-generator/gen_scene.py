#!/usr/bin/env python3
"""Option D with a hand-drawn, living street: the Events Wall on a 100-event
day. Desktop walks through the day; the phone shows the next few (as C).
Usage: gen_scene.py [assets.json]"""
import json, os, sys
import scene_art as A
from gen_wall2 import list_html, ruler_html, LEFT, RIGHT, ARROW_BTN

H = A.H
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'events-canvas')
PROJ = os.path.join(ROOT, 'project')
AMAP = json.load(open(sys.argv[1])) if len(sys.argv) > 1 else {}
AS = lambda f: AMAP.get(f, 'assets/' + f)
STYLES = ['day-rings', 'evening-waves', 'day-checks', 'evening-bubbles', 'evening-rings']

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
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@500;700&amp;display=swap" rel="stylesheet">
<style>body{margin:0}</style>
</helmet>
<div style="position: absolute; inset: 0; font-family: Geist, system-ui, sans-serif; filter: drop-shadow(0 7px 9px rgba(40,25,10,.28)) drop-shadow(0 1px 1px rgba(40,25,10,.25))">
  <span aria-hidden="true" style="position: absolute; inset: 0; background: url({{src}}) center / 100% 100% no-repeat"></span>
  <span style="position: absolute; inset: 0; background: linear-gradient(118deg, rgba(255,255,255,.20), rgba(255,255,255,0) 32%, rgba(0,0,0,0) 68%, rgba(0,0,0,.12)); mix-blend-mode: soft-light; pointer-events: none"></span>
  <sc-if value="{{ph}}" hint-placeholder-val="{{ false }}">
    <span style="position: absolute; right: 5%; top: 3.6%; display: flex; align-items: center; height: 20px; padding: 0 7px; border-radius: 6px; background: #211d15; color: #fffdf8; font-size: 10.5px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap">{{tag}}</span>
  </sc-if>
  <sc-if value="{{saved}}" hint-placeholder-val="{{ false }}">
    <span style="position: absolute; right: 5%; bottom: 4%; display: flex; align-items: center; gap: 4px; height: 22px; padding: 0 8px; border-radius: 999px; background: #c23b0e; color: #fff; font-size: 11px; font-weight: 700">Saved</span>
  </sc-if>
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{"p":{"editor":null},"$preview":{"width":200,"height":280}}'>
class Component extends DCLogic {
  renderVals() {
    const p = this.props.p || { ph: true, src: '__SAMPLE__', tag: '18:00 · #61', alt: 'Layout placeholder poster' };
    return { src: p.src, alt: p.alt || '', ph: !!p.ph, tag: p.tag || '', saved: !!p.saved };
  }
}
</script>
</body>
</html>
'''

LOGIC = r'''
class Component extends DCLogic {
  cfg() { return __CFG__; }
  asset(f) { return (__AMAP__)[f] || ('assets/' + f); }
  componentDidMount() {
    this._still = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    // The column rolls to its next poster every six seconds.
    this.restartRoll();
  }
  // Rolling it by hand gives each poster its full six seconds again.
  restartRoll() {
    clearInterval(this._timer);
    if (!this._still) this._timer = setInterval(() => this.tick(), 6000);
  }
  componentWillUnmount() { clearInterval(this._timer); clearTimeout(this._snap); }
  tick() {
    const s = this.state || {};
    if (s.hold || s.sel || s.lift || (this._d && this._d.col) || this._spin) return;
    if (Object.keys(s.turned || {}).some((k) => s.turned[k] && k.indexOf('column:') === 0)) return;
    this.roll(1, { snap: false });
  }
  roll(dir, done) {
    const pos = (this.state && this.state.pos) || 0;
    const N = Math.max(1, this.colList().length);
    const c = this.cfg();
    if (c.col.drum && this.drumEl()) {
      // A round drum turns to the next poster, easing in and out.
      this.spinTo((this._spin ? this._spin.k : pos) + dir, 0, done && done.snap ? 520 : 1100);
      return;
    }
    const end = Object.assign({ anim: true }, done);
    // The drum carries its posters twice, so it wraps without a jump.
    if (dir > 0 && pos >= N) {
      this.setState({ pos: pos - N, anim: false });
      this._snap = setTimeout(() => this.setState(Object.assign({ pos: pos - N + 1 }, end)), 40);
      return;
    }
    if (dir < 0 && pos <= 0) {
      this.setState({ pos: pos + N, anim: false });
      this._snap = setTimeout(() => this.setState(Object.assign({ pos: pos + N - 1 }, end)), 40);
      return;
    }
    this.setState(Object.assign({ pos: pos + dir }, end));
  }
  // What the column rolls: tonight's posters, one per hour from 18:00.
  colList() {
    const { ev } = this.events();
    return [18, 19, 20, 21].map((h) => ev.find((e) => e.h === h)).filter(Boolean);
  }
  colPrefix() { return 'Tonight'; }
  // Where "now" is on the walking wall.
  nowX() {
    const it = this.items().find((i) => i.h === 13);
    return it ? it.x : 0;
  }
  events() {
    if (this._ev) return this._ev;
    const COUNTS = { 8: 2, 9: 3, 10: 6, 11: 5, 12: 4, 13: 6, 14: 5, 15: 7, 16: 8, 17: 9, 18: 11, 19: 10, 20: 9, 21: 7, 22: 5, 23: 3 };
    const STY = __STYLES__;
    const two = (h) => (h < 10 ? '0' : '') + h;
    const ev = [];
    let n = 0;
    for (let h = 8; h <= 23; h++) {
      for (let i = 0; i < COUNTS[h]; i++) {
        n++;
        let e = { n, h, ph: true, title: 'Placeholder ' + n, time: two(h) + ':··', src: this.asset('poster-' + STY[n % STY.length] + '.png'), tag: two(h) + ':00 · #' + n, alt: 'Layout placeholder poster ' + n + ', not a real event' };
        if (h === 18 && i === 0) e = { n, h, ph: false, title: 'Street culture night', time: '18:30', until: 'until 21:00', place: 'Rheinauhafen', price: 'Price not listed', cat: 'Culture', src: this.asset('poster-street.png'), alt: 'Street culture night, 18:30, Rheinauhafen' };
        if (h === 20 && i === 0) e = { n, h, ph: false, title: 'Cinema under the stars', time: '20:00', until: 'until 22:00', place: 'Stadtgarten', price: 'Price not listed', cat: 'Culture', src: this.asset('poster-cinema.png'), alt: 'Cinema under the stars, 20:00, Stadtgarten', rain: true };
        ev.push(e);
      }
    }
    this._ev = { ev, COUNTS, two };
    return this._ev;
  }
  /* The sky at an hour on Wed 30 Sept in Cologne, worked out as the mock
     does (events-next/sky.js): the sun's real height, the moon's phase and
     the sample forecast hour by hour. */
  sky(hour) {
    const c = this.cfg();
    const P = this.props || {};
    const rad = Math.PI / 180;
    const clamp = (v, lo = 0, hi = 1) => Math.max(lo, Math.min(hi, v));
    const lat = 50.9384 * rad, lng = 6.9599, offset = 120, n = 273;
    const g = ((2 * Math.PI) / 365) * (n - 1);
    const eq = 229.18 * (0.000075 + 0.001868 * Math.cos(g) - 0.032077 * Math.sin(g) - 0.014615 * Math.cos(2 * g) - 0.040849 * Math.sin(2 * g));
    const decl = 0.006918 - 0.399912 * Math.cos(g) + 0.070257 * Math.sin(g) - 0.006758 * Math.cos(2 * g) + 0.000907 * Math.sin(2 * g) - 0.002697 * Math.cos(3 * g) + 0.00148 * Math.sin(3 * g);
    const noon = (720 - 4 * lng - eq + offset) / 60;
    const cosH = (Math.cos(90.833 * rad) - Math.sin(lat) * Math.sin(decl)) / (Math.cos(lat) * Math.cos(decl));
    const half = Math.acos(clamp(cosH, -1, 1)) / rad / 15;
    const rise = noon - half, set = noon + half;
    const height = Math.asin(Math.sin(lat) * Math.sin(decl) + Math.cos(lat) * Math.cos(decl) * Math.cos((hour - noon) * 15 * rad)) / rad;
    // Sample weather (events-next/sample-weather.js): sky, °C, chance of rain.
    const DAY = [['clear',11,0],['clear',11,0],['clear',10,0],['clear',10,0],['mist',9,0],['mist',9,0],['mist',10,0],['partly',11,0],['partly',13,0],['partly',15,0],['clear',17,0],['clear',19,0],['clear',20,0],['clear',21,0],['clear',21,0],['partly',21,0],['partly',20,10],['cloudy',19,30],['showers',17,60],['showers',16,70],['rain',15,70],['cloudy',14,40],['partly',13,10],['clear',12,0]];
    const looks = { clear: { cover: 0, rain: 0, mist: 0 }, partly: { cover: 0.4, rain: 0, mist: 0 }, cloudy: { cover: 0.85, rain: 0, mist: 0 }, showers: { cover: 0.8, rain: 0.6, mist: 0 }, rain: { cover: 1, rain: 1, mist: 0.2 }, mist: { cover: 0.25, rain: 0, mist: 1 } };
    const hi = Math.min(23, Math.floor(clamp(hour, 0, 23.99)));
    // A past day has no weather on record: draw a plain sky, no rain.
    const forced = P.weather && P.weather !== 'forecast' ? P.weather : this._forceClear ? 'clear' : null;
    const a = looks[forced || DAY[hi][0]], b = looks[forced || DAY[Math.min(23, hi + 1)][0]];
    const t = forced ? 0 : hour - hi;
    const cover = a.cover + (b.cover - a.cover) * t, rain = a.rain + (b.rain - a.rain) * t, mist = a.mist + (b.mist - a.mist) * t;
    const skyName = forced || DAY[hi][0];
    // Colour by the sun's height (the mock's palette), greyed by cloud.
    const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const toHex = (cc) => '#' + cc.map((v) => Math.round(clamp(v, 0, 255)).toString(16).padStart(2, '0')).join('');
    const mix = (x, y, k) => { const p = rgb(x), q = rgb(y); return toHex(p.map((v, i) => v + (q[i] - v) * k)); };
    const overcast = (hex, cv) => { const cc = rgb(hex); const grey = 0.3 * cc[0] + 0.59 * cc[1] + 0.11 * cc[2]; return toHex(cc.map((v) => (v + (grey - v) * 0.72 * cv) * (1 - 0.14 * cv))); };
    const skies = [
      [-18, ['#0e0a2b','#140f3a','#1b1449','#221a57','#2c2264','#2a2160','#07051a','#10153d','#3a3560']],
      [-9, ['#1c1650','#2a1f78','#3a2a9a','#55309f','#7a4a9e','#352a75','#0e0a2c','#18205a','#5a4d86']],
      [-3, ['#24175e','#33208a','#4b2bb8','#7e36ae','#d9785e','#3b2a7a','#140f36','#1a2257','#8a6aa8']],
      [2, ['#3c3a8f','#6a5aa8','#c27aa0','#f0a070','#ffc46b','#5a5590','#1d1a40','#2d3570','#f3c2a0']],
      [8, ['#5fa8d6','#86bde0','#b6d4e0','#f2d9b0','#ffd38a','#6f87a8','#2b3550','#3f6f94','#fff1de']],
      [20, ['#7cc6e0','#94d1e5','#b4dfeb','#d6ecee','#f3dfa6','#6f87a8','#2b3550','#3f6f94','#ffffff']],
      [40, ['#5bb4e6','#7cc4ea','#a0d4ee','#c8e6f2','#e8f2ec','#6f87a8','#2b3550','#3f6f94','#ffffff']],
    ];
    const hh = clamp(height, -18, 40);
    let i = 0;
    while (i < skies.length - 2 && skies[i + 1][0] < hh) i++;
    const [h0, from] = skies[i], [h1, to] = skies[i + 1];
    const k = clamp((hh - h0) / (h1 - h0));
    const col = from.map((x, j) => mix(x, to[j], k)).map((x, j) => (j < 5 || j === 7 ? overcast(x, cover) : x));
    // Sun and moon cross the sky between rising and setting.
    const across = (share) => c.W * 0.05 + clamp(share, -0.1, 1.1) * c.W * 0.9;
    const up = (ht) => Math.max(c.SKY * 0.16, c.SKY * 0.98 - (ht / 40) * c.SKY * 0.85);
    const at = Date.UTC(2026, 8, 30) + (hour * 60 - offset) * 6e4;
    const phase = ((((at - Date.UTC(2000, 0, 6, 18, 14)) / 864e5 / 29.530588853) % 1) + 1) % 1;
    const LD = 24.84;
    let d = hour - (noon + phase * LD);
    d = ((((d + LD / 2) % LD) + LD) % LD) - LD / 2;
    const mH = Math.asin(Math.cos(lat) * Math.cos(d * (360 / LD) * rad)) / rad;
    const lit = (1 - Math.cos(2 * Math.PI * phase)) / 2;
    const R = c.phone ? 9 : 15;
    const rx = (R * Math.abs(1 - 2 * lit)).toFixed(2);
    const waxing = phase < 0.5, gibbous = lit > 0.5;
    const moonPath = 'M0 ' + -R + 'A' + R + ' ' + R + ' 0 0 ' + (waxing ? 1 : 0) + ' 0 ' + R + 'A' + rx + ' ' + R + ' 0 0 ' + (waxing === gibbous ? 1 : 0) + ' 0 ' + -R + 'Z';
    const night = clamp((-height - 6) / 6);
    const lamp = clamp((4 - height) / 6);
    const dark = clamp((2 - height) / 12);
    const day = clamp((height + 2) / 6);
    const names = { partly: 'Partly cloudy', cloudy: 'Cloudy', showers: 'Showers', rain: 'Rain', mist: 'Mist' };
    const ICON = {
      sun: 'M12 8a4 4 0 1 0 0 8a4 4 0 1 0 0-8ZM12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4',
      moon: 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z',
      partly: 'M8.5 5.5a3 3 0 1 0 0 6a3 3 0 1 0 0-6ZM8.5 2.5v1.2M2.5 8.5h1.2M4.3 4.3l.9.9M12.7 4.3l-.9.9M9.5 20h7.5a3.5 3.5 0 0 0 0-7a4.6 4.6 0 0 0-8.8 1.5A2.8 2.8 0 0 0 9.5 20Z',
      cloud: 'M7 18h10a4 4 0 0 0 0-8a5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 18Z',
      rain: 'M7 14.5h10a4 4 0 0 0 0-8a5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 14.5ZM9 17.5l-1 3M13 17.5l-1 3M17 17.5l-1 3',
      mist: 'M4 8h16M6 12h12M4 16h16',
    };
    const glyph = { clear: height > 0 ? 'sun' : 'moon', partly: 'partly', cloudy: 'cloud', showers: 'rain', rain: 'rain', mist: 'mist' }[skyName] || 'cloud';
    const mm = Math.round((hour % 1) * 60);
    const f3 = (v) => Number(v.toFixed(3));
    return {
      s1: col[0], s2: col[1], s3: col[2], s4: col[3], s5: col[4], far: col[5], near: col[6],
      cloud: mix(col[8], '#9a9cae', clamp(cover * 0.5 + rain * 0.4)), cloudShade: mix(col[8], '#5c5f74', 0.3 + rain * 0.45),
      mistC: mix(col[4], height > 0 ? '#eef2f4' : '#6e6c90', 0.6),
      sunX: f3(across((hour - rise) / (set - rise))), sunY: f3(up(height)), sunO: f3(clamp((height + 3) / 4) * (1 - cover * 0.75)), sunC: mix('#ffa947', '#ffe363', clamp(height / 15)),
      moonX: f3(across((d + LD / 4) / (LD / 2))), moonY: f3(up(mH)), moonO: f3(lit > 0.03 ? clamp((mH + 2) / 4) * clamp((4 - height) / 6) * (1 - cover * 0.8) : 0), moonPath,
      stars: f3(night * clamp(1 - cover * 1.4)), windows: f3(clamp((3 - height) / 8 + (height < 12 ? cover * 0.25 : 0)) * 0.9),
      c1: f3(clamp(0.2 + cover * 0.8)), c2: f3(clamp(cover * 1.1)), c3: f3(clamp(cover * 1.3 - 0.25)),
      birds: f3(day * (1 - rain) * (1 - cover * 0.5)), plane: f3(night * (1 - cover * 0.6)), mist: f3(mist * 0.85),
      lamp: f3(lamp), glassDay: f3(1 - lamp * 0.85), night: f3(dark * 0.5), golden: f3(clamp(1 - Math.abs(height - 3) / 7) * (1 - cover * 0.6) * (height > -2 ? 1 : 0)),
      wet: f3(clamp(rain * 1.2)), rain: f3(rain), leaves: f3((1 - rain) * (height > -4 ? 1 : 0.5)),
      objB: f3(1 - dark * 0.55), shadow: f3(day * (1 - cover * 0.85) * 0.32), sunWash: f3(day * (1 - cover * 0.8) * 0.8), posterB: f3(1 - dark * 0.1), colGlow: f3(lamp * 0.55),
      temp: DAY[hi][1] + '°', name: skyName === 'clear' ? (height > 0 ? 'Sunny' : 'Clear') : names[skyName] || 'Cloudy',
      when: (hour < 10 ? '0' : '') + Math.floor(hour) + ':' + (mm < 10 ? '0' : '') + mm, icon: ICON[glyph],
      chipNote: forced ? 'Sample weather · tweaked' : 'Sample weather',
    };
  }
  // ---------- The round drum's motion ----------
  // The drum's turn lives here, as how far round it is (this._o, in px of
  // its surface) while it moves; it's painted straight onto the drum, and
  // the street's own redraws paint the same, so nothing jumps. When it
  // comes to rest on a poster, pos becomes that poster.
  drumEl() { return this.root && this.root.querySelector('[data-col-drum] [style*="preserve-3d"]'); }
  drumGeo() { const c = this.cfg().col; return { c, P: c.pitch, R: ((c.faces || 5) * c.pitch) / (2 * Math.PI) }; }
  drumAt() { return this._o != null ? this._o : ((this.state && this.state.pos) || 0) * this.cfg().col.pitch; }
  drumPaint() {
    const el = this.drumEl();
    if (!el) return;
    const { c, R } = this.drumGeo();
    el.style.transition = 'none';
    el.style.transform = 'perspective(' + c.depth + 'px) translateZ(' + (-R).toFixed(3) + 'px) rotateY(' + ((-this.drumAt() / R) * (180 / Math.PI)).toFixed(3) + 'deg)';
  }
  drumShowAll() { const el = this.drumEl(); if (el) el.querySelectorAll('.st-face').forEach((f) => (f.style.display = 'block')); }
  // To poster k: by default a spring that starts at the drum's own speed
  // (v, in px per ms), so a flick carries on and a slow release eases in;
  // with ms, an ease in and out over that time (the timed turn, the dots).
  spinTo(k, v, ms) {
    const { P } = this.drumGeo();
    const T = k * P, o0 = this.drumAt(), t0 = performance.now();
    cancelAnimationFrame(this._spinRaf);
    this._o = o0;
    this._spin = { k };
    this.drumShowAll();
    const W = 10;
    const step = (now) => {
      const t = (now - t0) / 1000;
      let done;
      if (ms) {
        const u = Math.min(1, (now - t0) / ms);
        const e = u < 0.5 ? 4 * u * u * u : 1 - Math.pow(-2 * u + 2, 3) / 2;
        this._o = o0 + (T - o0) * e;
        done = u >= 1;
      } else {
        const A = o0 - T, B = (v || 0) * 1000 + W * A;
        this._o = T + (A + B * t) * Math.exp(-W * t);
        done = t > 0.1 && Math.abs(this._o - T) < 0.35 && Math.abs((B - W * (A + B * t)) * Math.exp(-W * t)) < 6;
      }
      if (done) {
        this._o = T;
        this.drumPaint();
        this._spin = null;
        this.setState({ pos: k, anim: false, colDrag: 0 });
        this._o = null;
        return;
      }
      this.drumPaint();
      this._spinRaf = requestAnimationFrame(step);
    };
    this._spinRaf = requestAnimationFrame(step);
  }
  down(e) {
    // A drag that starts anywhere on the column (its posters, the old
    // ones below, the lamps, the dome) rolls the column; one on the wall's
    // posters moves them. Anywhere else (bare wall, sky, pavement) a drag
    // does nothing (owner, 13 Oct 2026).
    const at = (sel) => (e.target && e.target.closest ? e.target.closest(sel) : null);
    const column = at('[data-col]');
    // On one wall the whole day is in view: its posters don't move.
    if (!column && (this.cfg().oneWall || !at('[data-wall-zone]'))) { this._d = null; return; }
    const drum = column ? column.querySelector('[data-col-drum]') : null;
    // The column can be drawn smaller (a phone's is scaled down).
    const scaled = drum && drum.closest('[style*="scale("]');
    const k = scaled ? parseFloat((scaled.style.transform.match(/scale\(([\d.]+)/) || [])[1]) || 1 : 1;
    this._d = { x: e.clientX, y: e.clientY, moved: false, id: e.pointerId, el: e.currentTarget, col: !!drum, k, drum };
    if (drum && this.cfg().col.drum) {
      // A turning drum stops under the finger, where it is.
      const caught = !!this._spin;
      cancelAnimationFrame(this._spinRaf);
      this._spin = null;
      this._o = this.drumAt();
      Object.assign(this._d, { caught, o0: this._o, samples: [[performance.now(), this._o]] });
      // A tap that stops a turning drum only stops it (as on iOS): it
      // doesn't also open the poster under the finger.
      if (caught) {
        const swallow = (ev) => { ev.stopPropagation(); ev.preventDefault(); };
        drum.addEventListener('click', swallow, true);
        this._d.unswallow = () => setTimeout(() => drum.removeEventListener('click', swallow, true), 0);
      }
    }
  }
  // Phones (Stories): a mouse wheel or trackpad pages the posters too.
  wheel(e) {
    if (Math.abs(e.deltaY) < 20 || Date.now() - (this._wt || 0) < 450) return;
    this._wt = Date.now();
    const page = this.curPage();
    this.setState({ page: Math.max(0, Math.min(this.pages() - 1, page + (e.deltaY > 0 ? 1 : -1))) });
  }
  move(e) {
    const d = this._d;
    if (!d) return;
    if (d.col) {
      // The drum follows the finger, a poster's width at most each way
      // (the pointer is already in the street's own units, not the
      // column's).
      const dx = (e.clientX - d.x) / d.k;
      if (!d.moved && Math.abs(dx) < 6) return;
      if (!d.moved) { d.moved = true; try { d.el.setPointerCapture(d.id); } catch (err) { /* fine */ } }
      const col = this.cfg().col, pitch = col.pitch;
      if (col.drum && d.o0 != null) {
        // The drum follows the finger as far as it goes; two posters
        // either way it starts to resist, as an iOS picker does.
        const pos = (this.state && this.state.pos) || 0, lo = (pos - 2) * pitch, hi = (pos + 2) * pitch;
        let o = d.o0 - dx;
        if (o > hi) o = hi + (o - hi) / 3;
        if (o < lo) o = lo - (lo - o) / 3;
        this._o = o;
        if (!d.shown) { this.drumShowAll(); d.shown = true; }
        this.drumPaint();
        const now = performance.now();
        d.samples.push([now, o]);
        while (d.samples.length > 2 && now - d.samples[0][0] > 100) d.samples.shift();
        return;
      }
      d.dx = Math.max(-pitch, Math.min(pitch, dx));
      // A round drum turns straight away, without redrawing its posters
      // on every move (the next draw puts it where the state says).
      const turning = col.drum && d.drum && d.drum.querySelector('[style*="preserve-3d"]');
      if (turning) {
        if (!d.shown) { turning.querySelectorAll('.st-face[data-d="2"], .st-face[data-d="-2"]').forEach((f) => (f.style.display = 'block')); d.shown = true; }
        const R = ((col.faces || 5) * pitch) / (2 * Math.PI), pos = (this.state && this.state.pos) || 0;
        const turn = (-(pos * pitch - d.dx) / R) * (180 / Math.PI);
        turning.style.transition = 'none';
        turning.style.transform = 'perspective(' + col.depth + 'px) translateZ(' + (-R).toFixed(3) + 'px) rotateY(' + turn.toFixed(3) + 'deg)';
      } else this.setState({ colDrag: d.dx, anim: false });
      return;
    }
    // Posters page sideways, or up and down where the board says so.
    const dx = this.cfg().vertical ? e.clientY - d.y : e.clientX - d.x;
    if (!d.moved && Math.abs(dx) < 6) return;
    if (!d.moved) { d.moved = true; try { d.el.setPointerCapture(d.id); } catch (err) { /* fine */ } }
    // Past either end the wall only gives a little, as an iOS list does;
    // with nothing more to show it barely moves.
    let drag = dx;
    if (this.cfg().mode === 'walk') {
      const stops = this.stops(), max = stops[stops.length - 1] || 0, off = this.offset();
      let to = off - dx;
      if (to < 0) to /= 4;
      else if (to > max) to = max + (to - max) / 4;
      drag = off - to;
    }
    this.setState({ drag });
  }
  up() {
    const d = this._d;
    this._d = null;
    if (d && d.col && d.o0 != null) {
      d.unswallow?.();
      this.restartRoll();
      const { P } = this.drumGeo(), pos = (this.state && this.state.pos) || 0;
      if (!d.moved) {
        // A tap that caught a turning drum lets it settle on the nearest poster.
        if (d.caught) this.spinTo(Math.round(this.drumAt() / P), 0);
        else this._o = null;
        return;
      }
      // Let go: the drum carries on at its speed and settles on the
      // poster it would come to, at most two along.
      const [t0, o0] = d.samples[0], [t1, o1] = d.samples[d.samples.length - 1];
      const v = t1 > t0 ? (o1 - o0) / (t1 - t0) : 0;
      const k = Math.max(pos - 2, Math.min(pos + 2, Math.round((this.drumAt() + v * 220) / P)));
      this.spinTo(k, v);
      return;
    }
    if (!d || !d.moved) return;
    if (d.col) {
      // A fifth of a poster rolls to the next or the previous one;
      // less springs back.
      const dx = d.dx || 0;
      this.restartRoll();
      if (Math.abs(dx) > this.cfg().col.pitch * 0.2) this.roll(dx < 0 ? 1 : -1, { colDrag: 0, snap: true });
      else this.setState({ colDrag: 0, anim: true, snap: true });
      return;
    }
    this.settle((this.state && this.state.drag) || 0);
  }
  settle(dx) {
    const c = this.cfg();
    if (c.mode === 'walk') {
      const stops = this.stops();
      const now = this.offset();
      let best = stops[0];
      stops.forEach((x) => { if (Math.abs(x - (now - dx)) < Math.abs(best - (now - dx))) best = x; });
      if (best === now && Math.abs(dx) > 40) {
        const i = stops.indexOf(best) + (dx < 0 ? 1 : -1);
        best = stops[Math.max(0, Math.min(stops.length - 1, i))];
      }
      this.setState({ drag: 0, offset: best });
    } else {
      let page = this.curPage();
      if (dx < -40) page = Math.min(this.pages() - 1, page + 1);
      if (dx > 40) page = Math.max(0, page - 1);
      this.setState({ drag: 0, page });
    }
  }
  items() {
    const c = this.cfg();
    const list = [];
    for (let h = 8; h <= 23; h++) list.push({ h, w: c.bayW });
    let x = 0;
    list.forEach((it) => { it.x = x; x += it.w; });
    this._total = x;
    return list;
  }
  stops() {
    const c = this.cfg();
    const its = this.items();
    const max = Math.max(0, this._total - c.zoneW);
    const out = [];
    its.forEach((it) => { if (it.x <= max) out.push(it.x); });
    if (out[out.length - 1] !== max) out.push(max);
    return out;
  }
  offset() {
    const s = this.state || {};
    if (typeof s.offset === 'number') return s.offset;
    return this.nowX();
  }
  match() { return true; }
  nowPage() {
    const c = this.cfg();
    if (c.pool !== 'day') return 0;
    const i = this.pool().findIndex((e) => e.h >= 13);
    return i < 0 ? 0 : Math.floor(i / c.per);
  }
  curPage() {
    const st = this.state || {};
    const max = Math.max(0, this.pages() - 1);
    return Math.min(max, typeof st.page === 'number' ? st.page : this.nowPage());
  }
  pool() {
    const { ev } = this.events();
    const c = this.cfg();
    if (c.pool === 'day') return ev.filter((e) => this.match(e));
    return c.pool === 'all' ? ev : ev.filter((e) => e.h >= 13).slice(0, 6);
  }
  pages() { return Math.ceil(this.pool().length / this.cfg().per); }
  step(dir) {
    const stops = this.stops();
    let i = stops.indexOf(this.offset());
    if (i < 0) i = 0;
    this.setState({ offset: stops[Math.max(0, Math.min(stops.length - 1, i + dir * (this.cfg().stepBays || 1)))], drag: 0 });
  }
  renderVals() {
    const c = this.cfg();
    const P = this.props || {};
    const s = Object.assign({ drag: 0, page: 0, open: {}, focus: 0, pos: 0, anim: true, saved: {}, sel: 0, plan: false }, this.state || {});
    const { ev, COUNTS, two } = this.events();
    const go = (patch) => () => this.setState(patch);
    const openHour = (h) => () => { const open = Object.assign({}, s.open); open[h] = true; this.setState({ open, focus: h }); };
    const pick = (e) => () => this.setState({ sel: e.n, plan: false });
    const dress = (e, extra) => Object.assign({}, e, { saved: !!s.saved[e.n], open: pick(e) }, extra);
    let skyHour = 13 + 10 / 60;
    let bays = [], grid = [], stripStyle = '', gridStyle = '';
    const visibleHours = [];
    if (c.mode === 'walk') {
      const its = this.items();
      const x = this.offset() - s.drag;
      stripStyle = 'transform: translateX(' + (-x) + 'px); transition: ' + (s.drag ? 'none' : 'transform 420ms cubic-bezier(0.22,1,0.36,1)') + ';';
      bays = its.map((it) => {
        const left = it.x - x;
        if (left + it.w > 30 && left < c.zoneW - 30) visibleHours.push(it.h);
        // Saved posters take the hour's spots first, then the earliest.
        const here = ev.filter((e) => e.h === it.h && this.match(e)).sort((a, b) => (s.saved[b.n] ? 1 : 0) - (s.saved[a.n] ? 1 : 0) || a.n - b.n);
        const posters = here.slice(0, c.spots || 2).map((e, i) => dress(e, { x: c.px, y: c.rows[i], w: c.pw, h: c.ph, rot: [-1.2, 0.8, -0.5, 1.1][(e.n + i) % 4] }));
        const more = here.length - posters.length;
        return Object.assign({}, it, {
          label: two(it.h) + ':00', isNow: it.h === 13 && c.nowPill !== false, op: it.h < 13 ? 0.72 : 1, posters,
          empty: posters.length === 0, emptyLabel: 'Nothing at ' + two(it.h) + ':00 matches',
          hasMore: more > 0, moreLabel: '+' + more + ' more', moreHref: c.oneWall ? '#' : '#g' + it.h,
          // On one wall, "+N more" opens the hour's stories.
          more: c.oneWall ? (ev2) => { if (ev2 && ev2.preventDefault) ev2.preventDefault(); const seen = Object.assign({}, s.seen); seen[it.h] = true; this.setState({ story: { h: it.h, i: 0, t: Date.now() }, seen, sel: 0, stPause: false }); } : openHour(it.h),
        });
      });
      const settledFirst = its.find((it) => it.x >= this.offset() - 1);
      skyHour = settledFirst && settledFirst.h !== 13 ? settledFirst.h + 0.5 : 13 + 10 / 60;
    } else {
      const pool = this.pool();
      const page = this.curPage();
      const slice = pool.slice(page * c.per, page * c.per + c.per);
      slice.forEach((e) => visibleHours.push(e.h));
      if (c.vertical) {
        // Every page sits below the one before; the column of posters moves up.
        gridStyle = 'transform: translateY(' + (s.drag - page * c.pageH) + 'px); transition: ' + (s.drag ? 'none' : 'transform 380ms cubic-bezier(0.22,1,0.36,1)') + ';';
        grid = pool.map((e, i) => ({ e, i })).filter(({ i }) => Math.abs(Math.floor(i / c.per) - page) <= 2)
          .map(({ e, i }) => dress(e, { x: c.cols[0], y: c.rows[i % c.per] + Math.floor(i / c.per) * c.pageH, w: c.pw, h: c.ph, rot: [-1.2, 0.8, -0.5, 1.1][(e.n + i) % 4] }));
      } else {
        gridStyle = 'transform: translateX(' + s.drag + 'px); transition: ' + (s.drag ? 'none' : 'transform 260ms ease') + ';';
        grid = slice.map((e, i) => dress(e, { x: c.cols[i % c.cols.length], y: c.rows[Math.floor(i / c.cols.length)], w: c.pw, h: c.ph, rot: [-1.2, 0.8, -0.5, 1.1][(e.n + i) % 4] }));
      }
      skyHour = slice.length && slice[0].h !== 13 ? slice[0].h + 0.5 : 13 + 10 / 60;
    }
    const AT = { dawn: 7.1, morning: 9.5, now: 13 + 10 / 60, sunset: 18.85, dusk: 19.6, night: 22.5 };
    if (P.skyAt && AT[P.skyAt] !== undefined) skyHour = AT[P.skyAt];
    const k = this.sky(skyHour);
    // The column rolls through tonight's posters, one per hour from 18:00.
    const tonight = this.colList();
    const N = Math.max(1, tonight.length);
    // Posters wrap round the drum edge to edge: the one before shows at
    // the left too, so the drum's sides are never bare.
    const strip = [];
    const ease = 'transition: ' + (s.anim ? 'transform ' + (s.snap ? 520 : 1100) + 'ms cubic-bezier(0.65,0,0.35,1)' : 'none') + ';';
    let colStyle;
    if (c.col.drum && tonight.length) {
      // A real drum (owner, 13 Oct 2026): five posters round it, a fifth
      // of a turn each, every poster bent round the curve in slices.
      // Rolling turns the drum. The poster facing you is pos's; the next
      // ones are to its right, the earlier ones to its left; the two out
      // of sight behind take whatever comes next. Every poster you can see
      // takes taps (one turning in is as tappable as the one facing you).
      // A drum can carry rings of posters one above the other (c.col.rings,
      // each turning with the rest); the page's has one ring of tall posters.
      const r3 = (v) => Math.round(v * 1000) / 1000;
      const F = c.col.faces || 5, A = 360 / F, rings = c.col.rings || [{ top: 4, ahead: 0 }];
      const P = c.col.pitch, R = (F * P) / (2 * Math.PI), S = c.col.slices || 6;
      const sw = P / S, chord = 2 * R * Math.tan(Math.PI / F / S) + 0.6;
      for (const ring of rings) for (let j = 0; j < F; j++) {
        const m = (((j - s.pos) % F) + F) % F, d = m > F / 2 ? m - F : m;
        // The faces out of sight behind are kept, but not drawn until
        // the drum turns.
        const away = !this._spin && !(this._d && this._d.shown) && Math.abs(d) > (c.col.reach == null ? 2 : c.col.reach);
        const e = tonight[(((s.pos + d + ring.ahead) % N) + N) % N];
        for (let k = 0; k < S; k++) {
          const a = j * A + (k + 0.5 - S / 2) * (A / S);
          strip.push(dress(e, {
            sx: r3((c.col.win - chord) / 2), sw: r3(chord), off: r3(chord / 2 - (k + 0.5) * sw), w: c.col.pw, h: c.col.ph, top: ring.top,
            tf: 'rotateY(' + r3(a) + 'deg) translateZ(' + r3(R) + 'px)', pe: Math.abs(d) <= 1 ? 'auto' : 'none', disp: away ? 'none' : 'block', d,
          }));
        }
      }
      const turn = (-(this._o != null ? this._o : s.pos * P) / R) * (180 / Math.PI);
      colStyle = 'width:' + c.col.win + 'px; transform-style: preserve-3d; transform: perspective(' + c.col.depth + 'px) translateZ(' + r3(-R) + 'px) rotateY(' + r3(turn) + 'deg); transition: none;';
    } else {
      for (let i = -1; i <= 2 * N && tonight.length; i++) {
        const e = tonight[((i % N) + N) % N];
        // Only the poster facing you takes taps; the edges of its
        // neighbours are just the drum's curve.
        strip.push(dress(e, { left: i * c.col.pitch, w: c.col.pw, h: c.col.ph, pe: i === s.pos ? 'auto' : 'none' }));
      }
      colStyle = 'transform: translateX(' + (c.col.inset - s.pos * c.col.pitch + (s.colDrag || 0)) + 'px); ' + ease;
    }
    const current = (((s.pos || 0) % N) + N) % N;
    const dots = tonight.map((x, i) => ({ style: i === current ? 'background:#ffd27a;box-shadow:0 0 6px #ffd27a' : 'background:#6f6a4a', label: 'Show poster ' + (i + 1) + ' of ' + N, pick: () => (c.col.drum ? this.spinTo((s.pos || 0) + ((i - current + N) % N), 0, 900) : this.setState({ pos: (s.pos || 0) + ((i - current + N) % N), anim: true, snap: false })) }));
    const ruler = [];
    for (let h = 8; h <= 23; h++) {
      const on = visibleHours.indexOf(h) >= 0;
      ruler.push({
        label: two(h), bar: Math.max(4, COUNTS[h] * (c.phone ? 2 : 3)),
        barStyle: 'background:' + (on ? '#00768b' : '#d9cdb5'), labelStyle: on ? 'color:#00566a;font-weight:700' : 'color:#6e6657',
        pick: () => {
          if (c.mode === 'walk') {
            const its = this.items();
            const stops = this.stops();
            this.setState({ offset: Math.min(its.find((i) => i.h === h).x, stops[stops.length - 1]), drag: 0 });
          } else {
            const idx = this.pool().findIndex((e) => e.h === h);
            if (idx >= 0) this.setState({ page: Math.floor(idx / c.per) });
          }
        },
      });
    }
    const groups = [];
    const onWall = c.mode === 'page' && c.pool !== 'all' ? this.pool().map((e) => e.n) : [];
    for (let h = 8; h <= 23; h++) {
      const all = ev.filter((e) => e.h === h && onWall.indexOf(e.n) < 0);
      if (!all.length) continue;
      const open = !!s.open[h];
      const rows = (open ? all : all.slice(0, 2)).map((e) => Object.assign({}, e, { real: !e.ph, sk: 40 + ((e.n * 37) % 45) + '%', chip: e.cat || 'Category' }));
      const toggle = () => { const o = Object.assign({}, s.open); o[h] = !open; this.setState({ open: o }); };
      groups.push({ id: 'g' + h, head: two(h) + ':00 · ' + all.length + (all.length === 1 ? ' event' : ' events'), rows, hasToggle: all.length > 2, toggleLabel: open ? 'Show fewer' : 'Show ' + (all.length - 2) + ' more at ' + two(h) + ':00', toggle, headStyle: s.focus === h ? 'background:#def5f8;border-radius:10px;padding-left:10px;padding-right:10px' : '' });
    }
    // The poster you tapped, in a strip over the pavement.
    const sel = s.sel ? ev.find((e) => e.n === s.sel) : null;
    const panel = sel ? {
      real: !sel.ph, ph: sel.ph,
      title: sel.ph ? 'Layout placeholder #' + sel.n : sel.title,
      line: sel.ph ? two(sel.h) + ':00 hour · not a real event' : sel.time + ' · ' + sel.until + ' · ' + sel.place + ' · ' + sel.price,
      note: sel.ph ? 'It stands in for one of 100 events on a busy day, to test the layout.' : (sel.rain ? 'Rain likely at 20:00 · sample forecast' : 'Culture · sample listing'),
      saveLabel: s.saved[sel.n] ? 'Saved' : 'Save', savePressed: !!s.saved[sel.n],
      save: () => { const sv = Object.assign({}, s.saved); if (sv[sel.n]) delete sv[sel.n]; else sv[sel.n] = true; this.setState({ saved: sv }); },
      showPlan: !sel.ph, plan: go({ plan: true }), planOpen: s.plan,
      planText: 'In the app, this opens Travel with the way to ' + (sel.place || '') + '. It isn’t connected in this preview.',
    } : null;
    const atNow = c.mode === 'walk' ? this.offset() === this.nowX() : this.curPage() === this.nowPage();
    const pages = c.mode === 'page' ? this.pages() : 0;
    const page = c.mode === 'page' ? this.curPage() : 0;
    return {
      k, bays, grid, stripStyle, gridStyle, ruler, groups, strip, colStyle, dots,
      colLabel: this.colPrefix() + ' · ' + (current + 1) + ' of ' + N,
      colNext: () => this.roll(1), colPrev: () => this.roll(-1),
      hold: go({ hold: true }), release: go({ hold: false }),
      hasPanel: !!panel, panel: panel || { title: '', line: '', note: '', saveLabel: '', planText: '' }, closePanel: go({ sel: 0, plan: false }),
      down: (e) => this.down(e), move: (e) => this.move(e), up: () => this.up(), wheel: (e) => this.wheel(e),
      earlier: () => this.step(-1), later: () => this.step(1),
      backToNow: go({ offset: undefined, page: undefined, drag: 0 }), notAtNow: !atNow,
      pageLabel: c.pool === 'all' ? 'Page ' + (page + 1) + ' of ' + pages : 'Next 6 from 13:10 · ' + (page + 1) + ' of ' + pages,
      listTitle: c.mode === 'page' && c.pool !== 'all' ? 'The rest of today · 94 events' : 'All 100 events today',
      pdots: (() => { const out = []; const first = Math.max(0, Math.min(page - 3, pages - 7)); for (let i = first; i < Math.min(pages, first + 7); i++) out.push({ style: i === page ? 'width:22px;background:#211d15' : 'width:8px;background:#b9ab90', vstyle: i === page ? 'height:22px;background:#211d15' : 'height:8px;background:#b9ab90', pick: go({ page: i }), label: 'Page ' + (i + 1) }); return out; })(),
      prevPage: go({ page: Math.max(0, page - 1) }), nextPage: go({ page: Math.min(pages - 1, page + 1) }),
    };
  }
}
'''


def column(p, s=1.0, mx=False, phone=False, tall=False):
    """The advertising column: dome, scalloped trim, frieze, the rolling
    drum, older posters below, band with its four lamps, plinth. Local
    size 300x740 (scaled by s)."""
    W, Hh = 300, 740
    ribs = ''.join(f'<path d="M150 30 Q{150 + (i - 3) * 30} 50 {150 + (i - 3) * 46} 92" fill="none" stroke="#1d332a" stroke-width="2"/>' for i in range(7))
    scallops = ''.join(f'<path d="M{18 + i * 16} 92 a8 9 0 0 0 16 0" fill="#c9a94a" stroke="#8a6f22" stroke-width="1"/>' for i in range(17))
    beads = ''.join(f'<ellipse cx="{28 + i * 16}" cy="111" rx="4.5" ry="3.2" fill="#c9a94a"/>' for i in range(16))
    old = ('<g filter="url(#{p}torn)">'
           '<path d="M24 458 h120 v170 h-120 Z" fill="#e9d79a"/><path d="M30 470 h90 v10 h-90 Z M30 488 h70 v6 h-70 Z" fill="#7a5a2a" opacity="0.25"/>'
           '<path d="M150 452 h126 v186 h-126 Z" fill="#b9a6d6"/><circle cx="214" cy="520" r="38" fill="none" stroke="#efe6ff" stroke-width="8" opacity="0.7"/>'
           '<path d="M24 600 h252 v40 h-252 Z" fill="#9fc5c0" opacity="0.85"/>'
           '</g>').replace('{p}', p)
    if tall:
        old = ''  # the tall drum covers where the old posters were
    svg = f'''<svg width="{W}" height="{Hh}" viewBox="0 0 {W} {Hh}" aria-hidden="true" style="position:absolute;left:0;top:0;overflow:visible">
  <defs>
    <linearGradient id="{p}drum" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#16271f"/><stop offset="0.3" stop-color="#3f6252"/><stop offset="0.42" stop-color="#4f7564"/><stop offset="0.7" stop-color="#2a4639"/><stop offset="1" stop-color="#101d17"/>
    </linearGradient>
    <linearGradient id="{p}dome" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#1a2e25"/><stop offset="0.32" stop-color="#4c7563"/><stop offset="0.45" stop-color="#6d9683"/><stop offset="0.75" stop-color="#2b483b"/><stop offset="1" stop-color="#14231c"/>
    </linearGradient>
    <linearGradient id="{p}cyl" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#000" stop-opacity="0.62"/><stop offset="0.14" stop-color="#000" stop-opacity="0.18"/><stop offset="0.34" stop-color="#fff" stop-opacity="0.12"/><stop offset="0.48" stop-color="#fff" stop-opacity="0"/><stop offset="0.74" stop-color="#000" stop-opacity="0.1"/><stop offset="1" stop-color="#000" stop-opacity="0.6"/>
    </linearGradient>
    <linearGradient id="{p}gold" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#7a6020"/><stop offset="0.38" stop-color="#e8cc6e"/><stop offset="0.6" stop-color="#c9a94a"/><stop offset="1" stop-color="#5e4a16"/>
    </linearGradient>
  </defs>
  <ellipse cx="150" cy="736" rx="160" ry="9" fill="#000" opacity="0.28"/>
  <rect x="18" y="96" width="264" height="568" fill="url(#{p}drum)"/>
  {old}
  <rect x="18" y="450" width="264" height="194" fill="url(#{p}cyl)"/>
  <path d="M8 92 Q14 40 150 26 Q286 40 292 92 Z" fill="url(#{p}dome)"/>
  {ribs}
  <path d="M60 52 Q110 34 150 31" fill="none" stroke="#ffffff" stroke-opacity="0.35" stroke-width="3" stroke-linecap="round"/>
  <rect x="4" y="86" width="292" height="8" rx="3" fill="url(#{p}gold)"/>
  {scallops}
  <rect x="16" y="102" width="268" height="20" fill="#24392f"/>{beads}<rect x="16" y="102" width="268" height="20" fill="url(#{p}cyl)"/>
  <rect x="14" y="120" width="272" height="4" fill="url(#{p}gold)"/>
  <rect x="14" y="448" width="272" height="4" fill="url(#{p}gold)"/>
  <rect x="12" y="642" width="276" height="24" fill="#24392f"/><rect x="12" y="642" width="276" height="24" fill="url(#{p}cyl)"/><rect x="12" y="642" width="276" height="3" fill="url(#{p}gold)"/>
  <path d="M8 666 h284 l6 26 h-296 Z" fill="#2c4538"/><path d="M8 666 h284 l6 26 h-296 Z" fill="url(#{p}cyl)"/>
  <rect x="-6" y="692" width="312" height="40" rx="3" fill="#2c4538"/><rect x="-6" y="692" width="312" height="40" rx="3" fill="url(#{p}cyl)"/><rect x="-6" y="692" width="312" height="3" fill="#ffffff" opacity="0.12"/>
  <path d="M138 26 h24 l-6 -10 h-12 Z" fill="#2b483b"/><circle cx="150" cy="12" r="8" fill="url(#{p}gold)"/><path d="M148.5 4 v-14 h3 v14 Z" fill="#c9a94a"/>
</svg>'''
    # The drum window: the posters roll past behind the curve's shading.
    if mx == 'mock':
        chain_o = '<div class="wall-board" style="display:contents"><div class="wall-column" style="display:contents">'
        chain_c = '</div></div>'
        strip_html = (f'''      <sc-for list="{H('strip')}" as="q" hint-placeholder-count="3"><div class="st-face" data-d="{H('q.d')}" style="position:absolute;top:{H('q.top')}px;left:{H('q.sx')}px;width:{H('q.sw')}px;height:{H('q.h')}px;overflow:hidden;backface-visibility:hidden;transform:{H('q.tf')};pointer-events:{H('q.pe')};display:{H('q.disp')}">'''
                      f'''<div style="position:absolute;top:0;left:{H('q.off')}px;width:{H('q.w')}px"><mx-poster v="q" where="column"></mx-poster></div></div></sc-for>''')
    elif mx:
        import mx as MX
        chain_o, chain_c = MX.chain_open('column', 'wall-column-face')
        strip_html = (f'''      <sc-for list="{H('strip')}" as="q" hint-placeholder-count="3"><div class="wall-column-face mx mxc-col" style="position:absolute;top:4px;left:{H('q.left')}px">'''
                      + MX.poster_markup('q', 'column') + '</div></sc-for>')
    else:
        chain_o = chain_c = ''
        strip_html = f'''      <sc-for list="{H('strip')}" as="q" hint-placeholder-count="3">
        <button type="button" onClick="{H('q.open')}" aria-label="{H('q.alt')}" style="position:absolute;top:4px;left:{H('q.left')}px;width:{H('q.w')}px;height:{H('q.h')}px;padding:0;border:0;background:none;cursor:pointer">
          <dc-import name="WallPoster" p="{H('q')}" hint-size="100%,100%"></dc-import><span aria-hidden="true" style="position:absolute;inset:0;z-index:3"></span>
        </button>
      </sc-for>'''
    win_h = 518 if ((mx and phone) or tall) else 324
    win = f'''<div data-col-drum onPointerEnter="{H('hold')}" onPointerLeave="{H('release')}" style="position:absolute;left:18px;top:124px;width:264px;height:{win_h}px;overflow:hidden;background:#d8ccb0;filter:brightness({H('k.posterB')});transition:filter 1.2s">
    {chain_o}<div style="position:absolute;left:0;top:0;height:100%;width:2000px;{H('colStyle')}">
{strip_html}
    </div>{chain_c}
    <div style="position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,rgba(0,0,0,.24) 0%,rgba(0,0,0,.06) 11%,rgba(255,255,255,.12) 33%,rgba(255,255,255,0) 47%,rgba(0,0,0,.03) 74%,rgba(0,0,0,.22) 100%)"></div>
    <div style="position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse at 42% 45%,rgba(255,226,170,.55),rgba(255,200,120,.12) 60%,transparent 75%);mix-blend-mode:screen;opacity:{H('k.colGlow')};transition:opacity 1.2s"></div>
  </div>'''
    lamps = f'''<div style="position:absolute;left:0;right:0;top:642px;height:24px;display:flex;justify-content:center;gap:2px">
    <sc-for list="{H('dots')}" as="d" hint-placeholder-count="4"><button type="button" aria-label="{H('d.label')}" onClick="{H('d.pick')}" style="display:grid;place-items:center;width:30px;height:44px;margin-top:-10px;padding:0;border:0;background:none;cursor:pointer"><i style="display:block;width:9px;height:9px;border-radius:50%;{H('d.style')}"></i></button></sc-for>
  </div>
  <button type="button" aria-label="Previous poster" onClick="{H('colPrev')}" style="position:absolute;left:18px;top:250px;width:40px;height:80px;border:0;background:none;cursor:pointer;opacity:0"></button>
  <button type="button" aria-label="Next poster" onClick="{H('colNext')}" style="position:absolute;right:18px;top:250px;width:40px;height:80px;border:0;background:none;cursor:pointer;opacity:0"></button>'''
    return f'''<div style="position:absolute;width:{W}px;height:{Hh}px;transform:scale({s});transform-origin:0 0">
  <div style="position:absolute;inset:0;filter:brightness({H('k.objB')});transition:filter 1.2s">{svg}</div>
  {win}
  {lamps}
</div>'''


def css(W, p):
    return f'''body{{margin:0}}
.{p} *{{box-sizing:border-box}}
@media (prefers-reduced-motion: no-preference) {{
  .{p} .drift-a{{animation:{p}drift 190s linear infinite}}
  .{p} .drift-b{{animation:{p}drift 120s linear infinite}}
  .{p} .drift-c{{animation:{p}drift 80s linear infinite}}
  @keyframes {p}drift{{from{{transform:translateX(0)}}to{{transform:translateX(-{W}px)}}}}
  .{p} .tw{{animation:{p}tw 3.4s ease-in-out infinite}}
  .{p} .tw1{{animation-delay:-1.1s}} .{p} .tw2{{animation-delay:-2.2s;animation-duration:4.6s}} .{p} .tw3{{animation-delay:-.6s;animation-duration:2.7s}}
  @keyframes {p}tw{{0%,100%{{opacity:1}}50%{{opacity:.2}}}}
  .{p} .pulse{{transform-box:fill-box;transform-origin:center;animation:{p}pulse 7s ease-in-out infinite}}
  @keyframes {p}pulse{{0%,100%{{transform:scale(1)}}50%{{transform:scale(1.07)}}}}
  .{p} .flock{{animation:{p}flock 52s linear infinite}}
  @keyframes {p}flock{{0%{{transform:translate(-140px,40px)}}25%{{transform:translate({W * 0.25:.0f}px,22px)}}50%{{transform:translate({W * 0.5:.0f}px,34px)}}75%{{transform:translate({W * 0.75:.0f}px,14px)}}100%{{transform:translate({W + 140}px,26px)}}}}
  .{p} .wing{{transform-box:fill-box;transform-origin:center 80%;animation:{p}flap .5s ease-in-out infinite alternate}}
  .{p} .w1{{animation-delay:-.18s}} .{p} .w2{{animation-delay:-.33s;animation-duration:.6s}}
  @keyframes {p}flap{{to{{transform:scaleY(-.55)}}}}
  .{p} .plane{{animation:{p}plane 80s linear infinite}}
  @keyframes {p}plane{{from{{transform:translate({W + 40}px,24px)}}to{{transform:translate(-60px,6px)}}}}
  .{p} .blink{{animation:{p}blink 1.3s steps(2,jump-none) infinite}}
  @keyframes {p}blink{{0%{{opacity:1}}100%{{opacity:0}}}}
  .{p} .smoke{{transform-box:fill-box;transform-origin:center;animation:{p}smoke 7.5s ease-out infinite}}
  .{p} .s1{{animation-delay:-2.5s}} .{p} .s2{{animation-delay:-5s}}
  @keyframes {p}smoke{{0%{{transform:translate(0,0) scale(.5);opacity:0}}15%{{opacity:.55}}100%{{transform:translate(22px,-52px) scale(2.6);opacity:0}}}}
  .{p} .flick{{animation:{p}flick 11s steps(1) infinite}} .{p} .f1{{animation-duration:15s}} .{p} .f2{{animation-duration:19s;animation-delay:-6s}}
  @keyframes {p}flick{{0%,42%{{opacity:1}}43%,71%{{opacity:.12}}72%,100%{{opacity:1}}}}
  .{p} .sway{{animation:{p}sway 6.5s ease-in-out infinite alternate}}
  @keyframes {p}sway{{from{{transform:rotate(-1.1deg)}}to{{transform:rotate(1.3deg)}}}}
  .{p} .fall{{animation:{p}fall 17s linear infinite;opacity:0}}
  .{p} .fall1{{animation-duration:21s;animation-delay:-6s}} .{p} .fall2{{animation-duration:15s;animation-delay:-11s}} .{p} .fall3{{animation-duration:24s;animation-delay:-3s}} .{p} .fall4{{animation-duration:19s;animation-delay:-15s}}
  @keyframes {p}fall{{0%{{transform:translate(0,0) rotate(0);opacity:0}}6%{{opacity:1}}25%{{transform:translate(46px,calc(var(--fall)*.25)) rotate(130deg)}}50%{{transform:translate(-8px,calc(var(--fall)*.5)) rotate(240deg)}}75%{{transform:translate(70px,calc(var(--fall)*.76)) rotate(320deg)}}94%{{opacity:1}}100%{{transform:translate(40px,var(--fall)) rotate(400deg);opacity:0}}}}
  .{p} .flame{{animation:{p}flame 2.8s ease-in-out infinite}}
  @keyframes {p}flame{{0%,100%{{filter:brightness(1)}}40%{{filter:brightness(1.18)}}60%{{filter:brightness(.92)}}}}
  .{p} .glow{{transform-box:fill-box;transform-origin:center;animation:{p}glow 3.6s ease-in-out infinite}}
  @keyframes {p}glow{{0%,100%{{transform:scale(1)}}50%{{transform:scale(1.08)}}}}
  .{p} .rainfall{{animation:{p}rain .42s linear infinite}}
  @keyframes {p}rain{{from{{transform:translate(0,0)}}to{{transform:translate(-19px,64px)}}}}
  .{p} .splash{{transform-box:fill-box;transform-origin:center;animation:{p}splash 1.1s ease-out infinite}}
  .{p} .sp1{{animation-delay:-.3s}} .{p} .sp2{{animation-delay:-.6s;animation-duration:1.3s}} .{p} .sp3{{animation-delay:-.85s}}
  @keyframes {p}splash{{0%{{transform:scale(.2);opacity:.9}}100%{{transform:scale(2.4);opacity:0}}}}
  .{p} .pig-head{{transform-box:fill-box;transform-origin:15% 90%;animation:{p}peck 4.2s ease-in-out infinite}}
  .{p} .ph1{{animation-delay:-1.4s;animation-duration:5.1s}} .{p} .ph2{{animation-delay:-2.9s;animation-duration:3.6s}}
  @keyframes {p}peck{{0%,58%,100%{{transform:rotate(0)}}66%{{transform:rotate(40deg) translate(1px,3px)}}74%{{transform:rotate(0)}}82%{{transform:rotate(38deg) translate(1px,3px)}}90%{{transform:rotate(0)}}}}
  .{p} .pig-walk{{animation:{p}walk 15s ease-in-out infinite}} .{p} .pw1{{animation-duration:19s;animation-delay:-7s}} .{p} .pw2{{animation-duration:23s;animation-delay:-12s}}
  @keyframes {p}walk{{0%,100%{{transform:translateX(0)}}40%,52%{{transform:translateX(28px)}}}}
  .{p} .ripple{{transform-box:fill-box;transform-origin:center;animation:{p}splash 1.6s ease-out infinite}}
}}'''


def card(cfg):
    """The wall card: sky and city, the tree, the wall, the posters, light,
    the street furniture, the column, rain, then the controls."""
    p = cfg['p']
    W, Hc, SKY, TOP, BASE, PAVE = cfg['W'], cfg['H'], cfg['SKY'], cfg['TOP'], cfg['BASE'], cfg['PAVE']
    phone = cfg['phone']
    # A fluid street (the Events page on a desktop) is drawn as wide as it
    # can get (W) and shown as wide as the screen allows (--st-w, from W0):
    # the drainpipe, the stencil, the arrows and the poster zone keep to its
    # right edge.
    W0 = cfg.get('W0')
    ref = W0 if W0 else None
    back = f'''<svg width="{W}" height="{Hc}" viewBox="0 0 {W} {Hc}" aria-hidden="true" style="position:absolute;left:0;top:0">
  {A.defs(p, W, SKY)}
  {A.sky(p, W, SKY, ref=ref)}
  {A.skyline(p, W, SKY, phone=phone, ref=ref)}
  {A.wall(p, W, Hc, TOP, BASE, PAVE, phone=phone, holes=cfg['holes'], scraps=cfg['scraps'], plate=cfg['plate'], pipe_x=None if W0 else cfg['pipe'], stencil=not W0, ref=ref)}
  <g style="opacity:{H('k.wet')};transition:opacity 1.2s"><rect y="{TOP}" width="{W}" height="{BASE - TOP}" fill="url(#{p}wet)"/></g>
  <g style="opacity:{H('k.shadow')};transition:opacity 1.2s" fill="#3a2614" filter="url(#{p}softer)">{''.join(f'<path d="{d}"/>' for d in cfg['shadows'])}</g>
  <g transform="translate({cfg['tree'][0]} {cfg['tree'][1]})"><g style="filter:brightness({H('k.objB')});transition:filter 1.2s">{A.tree(p, cfg['tree'][2])}</g></g>
</svg>'''
    if W0:
        edge = 420
        back += f'''
  <svg width="{edge}" height="{Hc}" viewBox="{W0 - edge} 0 {edge} {Hc}" aria-hidden="true" style="position:absolute;right:0;top:0">{A.pipe(p, cfg['pipe'], TOP, BASE, 1.0)}{A.stencil_text(W0 - 34, BASE, PAVE)}</svg>'''
    zone_l, zone_w = cfg['zoneLeft'], cfg['zoneW']
    slot = (f'<button type="button" onClick="{H("P.open")}" aria-label="{H("P.alt")}" style="position:absolute;left:{H("P.x")}px;top:{H("P.y")}px;width:{H("P.w")}px;height:{H("P.h")}px;padding:0;border:0;background:none;cursor:pointer;transform:rotate({H("P.rot")}deg)">'
            f'<dc-import name="WallPoster" p="{H("P")}" hint-size="100%,100%"></dc-import><span aria-hidden="true" style="position:absolute;inset:0;z-index:3"></span></button>')
    chain_o = chain_c = ''
    if cfg.get('mx') == 'mock':
        chain_o = '<div class="wall-board" style="display:contents"><div class="wall-view wall-view--wall" style="display:contents">'
        chain_c = '</div></div>'
        slot = f'<div class="wall-day st-slot" style="position:absolute;left:{H("P.x")}px;top:{H("P.y")}px"><mx-poster v="P" where="wall"></mx-poster></div>'
    elif cfg.get('mx'):
        import mx as MX
        chain_o, chain_c = MX.chain_open('wall', 'wall-day')
        slot = f'<div class="wall-day mx mxc-wall" style="position:absolute;left:{H("P.x")}px;top:{H("P.y")}px">' + MX.poster_markup('P', 'wall') + '</div>'
    if cfg['mode'] == 'deck':
        posters = cfg['deck']
    elif cfg['mode'] == 'walk':
        chip = 'display:flex;align-items:center;height:26px;padding:0 10px;border-radius:6px;background:#211d15;color:#fffdf8;font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums'
        zone_x = f'right:{W0 - zone_l - zone_w}px' if W0 else f'width:{zone_w}px'
        posters = f'''<div data-wall-zone style="position:absolute;left:{zone_l}px;{zone_x};top:{TOP}px;bottom:{Hc - BASE}px;overflow:hidden">
    <div style="position:absolute;left:0;top:{-TOP}px;height:{Hc}px;width:5000px;{H('stripStyle')}">
      {chain_o}<sc-for list="{H('bays')}" as="b" hint-placeholder-count="3">
        <div class="st-bay" style="position:absolute;top:0;bottom:0;left:{H('b.x')}px;width:{H('b.w')}px;opacity:{H('b.op')}">
          <span style="position:absolute;left:12px;top:{cfg['labelY']}px;{chip}">{H('b.label')}</span>
          <sc-if value="{H('b.isNow')}" hint-placeholder-val="{{{{ false }}}}"><span style="position:absolute;left:82px;top:{cfg['labelY']}px;display:flex;align-items:center;height:26px;padding:0 9px;border-radius:999px;background:#c23b0e;color:#fff;font-size:12px;font-weight:700">Now 13:10</span></sc-if>
          <sc-if value="{H('b.hasMore')}" hint-placeholder-val="{{{{ false }}}}"><a href="{H('b.moreHref')}" onClick="{H('b.more')}" style="position:absolute;{('left:%dpx;top:%dpx;' % cfg['moreAt']) if cfg.get('moreAt') else ('right:%dpx;top:%dpx;' % (6 if phone else 16, cfg['labelY']))}display:flex;align-items:center;height:26px;padding:0 10px;border-radius:999px;background:#fff6c9;color:#211d15;font-size:12.5px;font-weight:700;text-decoration:none;box-shadow:0 2px 6px -3px #0006">{H('b.moreLabel')}</a></sc-if>
          <sc-if value="{H('b.empty')}" hint-placeholder-val="{{{{ false }}}}"><span style="position:absolute;left:12px;right:12px;top:{cfg['labelY'] + 60}px;padding:10px 12px;border:2px dashed #b9ab90;border-radius:10px;color:#6e6657;font-size:12.5px;text-align:center">{H('b.emptyLabel')}</span></sc-if>
          <sc-for list="{H('b.posters')}" as="P" hint-placeholder-count="2">{slot}</sc-for>
        </div>
      </sc-for>{chain_c}
    </div>
  </div>'''
    elif cfg.get('vertical'):
        vd = f'''<sc-for list="{H('pdots')}" as="d" hint-placeholder-count="3"><button type="button" aria-label="{H('d.label')}" onClick="{H('d.pick')}" style="display:grid;place-items:center;width:24px;height:30px;padding:0;border:0;background:none;cursor:pointer"><i style="display:block;width:7px;border-radius:4px;{H('d.vstyle')}"></i></button></sc-for>'''
        posters = f'''<div data-wall-zone onWheel="{H('wheel')}" style="position:absolute;left:{zone_l}px;width:{zone_w}px;top:{TOP}px;height:{BASE - TOP}px;overflow:hidden;touch-action:none">
    <div style="position:absolute;left:0;right:0;top:{-TOP}px;height:{Hc}px;{H('gridStyle')}">
      {chain_o}<sc-for list="{H('grid')}" as="P" hint-placeholder-count="2">{slot}</sc-for>{chain_c}
    </div>
  </div>
  <div role="group" aria-label="Pages" style="position:absolute;left:{zone_l - 22}px;top:{(TOP + BASE) // 2 - 45}px;display:flex;flex-direction:column;align-items:center;z-index:6">{vd}</div>'''
    else:
        posters = f'''<div data-wall-zone style="position:absolute;left:{zone_l}px;width:{zone_w}px;top:0;bottom:0;overflow:hidden">
    <div style="position:absolute;inset:0;{H('gridStyle')}">
      <sc-for list="{H('grid')}" as="P" hint-placeholder-count="2">{slot}</sc-for>
    </div>
  </div>'''
    lx, ly = cfg['lamp']
    light = f'''<div aria-hidden="true" style="position:absolute;left:0;right:0;top:{TOP}px;bottom:{Hc - PAVE}px;pointer-events:none">
    <div style="position:absolute;inset:0;background:#25205a;mix-blend-mode:multiply;opacity:{H('k.night')};transition:opacity 1.2s"></div>
    <div style="position:absolute;inset:0;background:linear-gradient(100deg,#ffb36b,#ff8f5a 60%,#ffbf80);mix-blend-mode:soft-light;opacity:{H('k.golden')};transition:opacity 1.2s"></div>
    <div style="position:absolute;inset:0;background:linear-gradient(155deg,rgba(255,244,214,.9),rgba(255,244,214,0) 55%);mix-blend-mode:soft-light;opacity:{H('k.sunWash')};transition:opacity 1.2s"></div>
    <div style="position:absolute;inset:0;background:radial-gradient(circle at {lx}px {ly - TOP}px,rgba(255,206,130,.75),rgba(255,178,90,.25) {cfg['lampR'] * 0.35:.0f}px,rgba(255,170,80,0) {cfg['lampR']}px);mix-blend-mode:screen;opacity:{H('k.lamp')};transition:opacity 1.2s"></div>
  </div>'''
    fs = cfg['front']
    pig = ''.join(f'<g transform="translate({x} {y}) scale({sc})">{A.pigeon(i, fl)}</g>' for i, (x, y, sc, fl) in enumerate(fs['pigeons']))
    front = f'''<svg width="{W}" height="{Hc}" viewBox="0 0 {W} {Hc}" aria-hidden="true" style="position:absolute;left:0;top:0;pointer-events:none;overflow:visible">
  <g style="opacity:{H('k.wet')};transition:opacity 1.2s">{''.join(f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{rx * 0.13:.1f}" fill="url(#{p}puddle)" opacity="0.8"/><ellipse class="ripple" cx="{x + 10}" cy="{y}" rx="{rx * 0.25:.0f}" ry="{rx * 0.04:.1f}" fill="none" stroke="#fff" stroke-opacity="0.5"/>' for x, y, rx in fs['puddles'])}</g>
  <g style="opacity:{H('k.lamp')};transition:opacity 1.2s"><ellipse cx="{lx}" cy="{PAVE + (Hc - PAVE) * 0.45:.0f}" rx="{cfg['lampR'] * 0.4:.0f}" ry="{(Hc - PAVE) * 0.4:.0f}" fill="#ffcf85" opacity="0.4" filter="url(#{p}softer)"/></g>
  <g style="filter:brightness({H('k.objB')});transition:filter 1.2s">
    <g transform="translate({fs['lampFoot'][0]} {fs['lampFoot'][1]}) scale({fs['lampScale']})">{A.gas_lamp()}</g>
    {f'<g transform="translate({fs["bench"][0]} {fs["bench"][1]}) scale({fs["bench"][2]})">{A.bench()}</g>' if fs.get('bench') else ''}
    {f'<g transform="translate({fs["bike"][0]} {fs["bike"][1]}) scale({fs["bike"][2]})">{A.bike()}</g>' if fs.get('bike') else ''}
    {pig}
  </g>
  <g transform="translate({fs['lampFoot'][0]} {fs['lampFoot'][1]}) scale({fs['lampScale']})">{A.lamp_glow().replace('#LAMP', '#' + p + 'lamp')}</g>
  <g style="opacity:{H('k.leaves')};transition:opacity 1.2s">{A.falling_leaves(*fs['leaves'])}</g>
</svg>'''
    cx, cy, cs = cfg['column']
    col = f'<div data-col style="position:absolute;left:{cx}px;top:{cy}px;width:{300 * cs:.0f}px;height:{740 * cs:.0f}px">{column(p + "c", cs, mx=(cfg.get('mx') if cfg.get('mx') == 'mock' else bool(cfg.get('mx'))), phone=phone, tall=cfg.get('tallDrum', False))}</div>'
    if fs.get('bikeFront'):
        front_bike = ''
    rainl = f'''<svg width="{W}" height="{Hc}" viewBox="0 0 {W} {Hc}" aria-hidden="true" style="position:absolute;left:0;top:0;pointer-events:none;opacity:{H('k.rain')};transition:opacity 1.2s">
  {A.rain(W, Hc, 1.0 if not phone else 1.3)}
  {A.splashes(W, PAVE + 2, 22 if not phone else 8)}
</svg>'''
    chipbox = f'''<div style="position:absolute;right:{cfg['chipR']}px;top:{cfg['chipT']}px;display:flex;align-items:center;gap:8px;padding:{'6px 10px' if phone else '8px 12px'};border-radius:12px;background:#fffdf8e0;box-shadow:0 2px 10px -6px #0006;z-index:7;font-size:{11 if phone else 12.5}px;line-height:1.25">
    <svg width="{20 if phone else 26}" height="{20 if phone else 26}" viewBox="0 0 24 24" fill="none" stroke="#211d15" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{H('k.icon')}"/></svg>
    <span><strong>{H('k.temp')} {H('k.name')}</strong><br>{H('k.when')} · {H('k.chipNote')}</span>
  </div>'''
    if cfg['mode'] == 'deck':
        controls = cfg.get('deckControls', '')
    elif cfg.get('vertical'):
        controls = f'''<sc-if value="{H('notAtNow')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('backToNow')}" style="position:absolute;right:{cfg['chipR']}px;top:{cfg['chipT'] + 48}px;height:34px;padding:0 12px;border:0;border-radius:999px;background:#c23b0e;color:#fff;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;z-index:7">Back to now</button></sc-if>'''
    elif cfg['mode'] == 'walk' and phone:
        controls = f'''<sc-if value="{H('notAtNow')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('backToNow')}" style="position:absolute;right:{cfg['chipR']}px;top:{cfg['chipT'] + 48}px;height:34px;padding:0 12px;border:0;border-radius:999px;background:#c23b0e;color:#fff;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;z-index:7">Back to now</button></sc-if>'''
    elif cfg['mode'] == 'walk' and cfg.get('oneWall'):
        controls = ''
    elif cfg['mode'] == 'walk':
        controls = f'''<button type="button" aria-label="Earlier" onClick="{H('earlier')}" style="{ARROW_BTN}; left:{zone_l - 24}px; top:{cfg['arrowY']}px; z-index:7">{LEFT}</button>
  <button type="button" aria-label="Later" onClick="{H('later')}" style="{ARROW_BTN}; right:34px; top:{cfg['arrowY']}px; z-index:7">{RIGHT}</button>
  <sc-if value="{H('notAtNow')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('backToNow')}" style="position:absolute;right:{cfg['chipR']}px;top:{cfg['chipT'] + 58}px;height:36px;padding:0 14px;border:0;border-radius:999px;background:#c23b0e;color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;z-index:7">Back to now · 13:10</button></sc-if>'''
    elif not phone:
        pdots = ('<div style="display:flex;align-items:center"><sc-for list="{{pdots}}" as="d" hint-placeholder-count="7"><button type="button" aria-label="{{d.label}}" onClick="{{d.pick}}" style="display:grid;place-items:center;width:24px;height:44px;padding:0;border:0;background:none;cursor:pointer"><i style="display:block;height:8px;border-radius:4px;{{d.style}}"></i></button></sc-for></div>' if cfg.get('dots') else '')
        controls = f'''<div style="position:absolute;left:{zone_l}px;width:{zone_w}px;top:{cfg['pagerY']}px;display:flex;justify-content:center;z-index:7"><div style="display:flex;align-items:center;gap:8px;padding:0 14px;min-height:40px;border-radius:999px;background:#fffdf8e8;box-shadow:0 2px 10px -6px #0006">{pdots}<strong style="font-size:13px;white-space:nowrap">{H('pageLabel')}</strong>{'' if cfg.get('dots') else '<span style="color:#4d4639;font-size:12.5px">· 94 more in the list below</span>'}</div></div>'''
        if cfg.get('arrows'):
            controls += f'''<button type="button" aria-label="Previous page" onClick="{H('prevPage')}" style="{ARROW_BTN}; left:{zone_l - 24}px; top:{cfg['arrowY']}px; z-index:7">{LEFT}</button>
  <button type="button" aria-label="Next page" onClick="{H('nextPage')}" style="{ARROW_BTN}; right:34px; top:{cfg['arrowY']}px; z-index:7">{RIGHT}</button>'''
    else:
        controls = ''
    pl = cfg['panel']
    panel = f'''<sc-if value="{H('hasPanel')}" hint-placeholder-val="{{{{ false }}}}">
    <div role="dialog" aria-label="{H('panel.title')}" style="position:absolute;left:{pl[0]}px;right:{pl[1]}px;bottom:{pl[2]}px;z-index:9;padding:{'12px 14px' if phone else '14px 18px'};border-radius:16px;background:#fffdf8f5;box-shadow:0 14px 34px -14px rgba(20,12,4,.5);font-size:{13 if phone else 14}px;line-height:1.35">
      <div style="display:flex;align-items:flex-start;gap:12px">
        <div style="flex:1;min-width:0"><strong style="display:block;font-size:{16 if phone else 18}px;letter-spacing:-.3px">{H('panel.title')}</strong><span style="display:block;color:#4d4639">{H('panel.line')}</span><span style="display:block;margin-top:2px;color:#6e6657;font-size:12.5px">{H('panel.note')}</span></div>
        <button type="button" aria-label="Close" onClick="{H('closePanel')}" style="display:grid;place-items:center;width:44px;height:44px;margin:-8px -8px 0 0;border:0;border-radius:12px;background:none;cursor:pointer;font-size:20px;color:#211d15">×</button>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
        <button type="button" aria-pressed="{H('panel.savePressed')}" onClick="{H('panel.save')}" style="height:44px;padding:0 16px;border:1.5px solid #211d15;border-radius:12px;background:#fffdf8;font:inherit;font-weight:600;cursor:pointer">{H('panel.saveLabel')}</button>
        <sc-if value="{H('panel.showPlan')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('panel.plan')}" style="height:44px;padding:0 16px;border:0;border-radius:12px;background:#c23b0e;color:#fff;font:inherit;font-weight:600;cursor:pointer">Plan the way</button></sc-if>
      </div>
      <sc-if value="{H('panel.planOpen')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:8px 0 0;padding:8px 10px;border-radius:10px;background:#def5f8;color:#00566a;font-size:12.5px">{H('panel.planText')}</p></sc-if>
    </div>
  </sc-if>'''
    if cfg.get('noPanel'):
        panel = ''
    margin = '0 19px' if phone else '0 0 0 32px'
    return f'''<div id="wall" class="{p}" style="position:relative;width:{f'var(--st-w, {W0}px)' if W0 else f'{W}px'};height:{Hc}px;margin:{margin};overflow:hidden;border-radius:{24 if phone else 28}px;touch-action:pan-y;user-select:none;scroll-margin-top:8px;background:#e7dbc4"{'' if cfg['mode'] == 'deck' or cfg.get('noDrag') else ' onPointerDown="' + H('down') + '" onPointerMove="' + H('move') + '" onPointerUp="' + H('up') + '" onPointerCancel="' + H('up') + '"'}>
  {back}
  {posters}
  {light}
  {col}
  {front}
  {'' if phone or cfg.get('noColLabel') else f'<div style="position:absolute;left:{cx}px;top:{cy + 700}px;width:300px;text-align:center;z-index:6;pointer-events:none"><span style="display:inline-block;padding:2px 10px;border-radius:4px;background:linear-gradient(#e8cc6e,#b8952f);color:#2a2008;font-size:11.5px;font-weight:700;letter-spacing:.04em;box-shadow:0 1px 0 #fff6 inset">{H("colLabel")}</span></div>'}
  {rainl}
  {cfg.get('emptyHtml', '')}
  {chipbox}
  {controls}
  {panel}
</div>'''


DESK = dict(
    p='wd', option='D', phone=False, mode='walk', W=1361, H=960, SKY=236, TOP=250, BASE=880, PAVE=924,
    zoneLeft=548, zoneW=789, bayW=263, px=36, pw=190, ph=266, rows=[294, 584], labelY=262, arrowY=520,
    holes=[(474, 660, 54, 42), (1262, 852, 70, 22), (118, 742, 40, 30)],
    scraps=[(590, 262, 130, 78, '#e8c66a', -3), (970, 812, 120, 64, '#9db4c0', 2), (446, 430, 82, 130, '#d9a8a8', -2), (1180, 254, 100, 56, '#c9b8d8', 1)],
    plate=(456, 334, 50), pipe=1336, tree=(0, -6, 1.0), lamp=(86, 332), lampR=440,
    shadows=['M436 300 L470 300 L540 880 L452 880 Z', 'M96 340 L102 340 L128 880 L118 880 Z'],
    column=(146, 194, 1.0), chipR=24, chipT=18, panel=(548, 34, 18),
    front=dict(lampFoot=(86, 936), lampScale=1.0, bench=(4, 936, 1.0), bike=(402, 938, 1.0),
               pigeons=[(214, 954, 1.35, False), (272, 957, 1.25, True), (360, 952, 1.4, False)],
               puddles=[(640, 944, 70), (930, 950, 52), (1170, 942, 84)], leaves=(6, 40, 120, 230, 800)),
)
PHONE = dict(
    p='wp', option='D', pool='next6', phone=True, mode='page', W=351, H=590, SKY=150, TOP=162, BASE=534, PAVE=558,
    zoneLeft=186, zoneW=160, per=2, cols=[16], rows=[176, 354], pw=124, ph=172,
    holes=[(176, 470, 18, 14)], scraps=[(190, 168, 56, 40, '#e8c66a', -3)],
    plate=(160, 196, 24), pipe=339, tree=(0, -4, 0.55), lamp=(14, 198), lampR=200,
    shadows=['M160 214 L176 214 L206 534 L166 534 Z'],
    column=(18, 200, 0.485), chipR=10, chipT=10, panel=(10, 10, 10),
    front=dict(lampFoot=(9, 566), lampScale=0.58, pigeons=[(70, 576, 0.62, False), (112, 580, 0.58, True)],
               puddles=[(250, 574, 34)], leaves=(3, 20, 60, 110, 440)),
)


# The other options, on the same living street.
DESK_A = dict(DESK, p='wa', option='A', bayW=197, zoneW=788, px=18, pw=160, ph=224, rows=[300, 556], moreAt=(12, 792), stepBays=2)
DESK_B = dict(DESK, p='wb', option='B', mode='page', pool='all', per=6, cols=[36, 299, 562], rows=[294, 584], dots=True, arrows=True, pagerY=884, ruler=True)
DESK_C = dict(DESK_B, p='wc', option='C', pool='next6', dots=False, arrows=False, ruler=False)
PHONE_A = dict(PHONE, p='pa', option='A', mode='walk', bayW=160, zoneW=160, spots=2, px=23, pw=114, ph=160, rows=[200, 368], labelY=168, nowPill=False, stepBays=1, ruler=True)
PHONE_B = dict(PHONE, p='pb', option='B', pool='all', dots=True, ruler=True)
PHONE_C = dict(PHONE, p='pc', option='C')
BOARDS = {
    'WallD-desktop.dc.html': (DESK, 'D · recommended · A’s walk at B’s size · living street · 1440'),
    'WallD-phone.dc.html': (PHONE, 'D · recommended · C on the phone · living street · 390'),
    'WallA-desktop.dc.html': (DESK_A, 'A · walk through the day · living street · 1440'),
    'WallA-phone.dc.html': (PHONE_A, 'A · walk through the day · living street · 390'),
    'WallB-desktop.dc.html': (DESK_B, 'B · paged wall · living street · 1440'),
    'WallB-phone.dc.html': (PHONE_B, 'B · paged wall · living street · 390'),
    'WallC-desktop.dc.html': (DESK_C, 'C · next 6, then the list · living street · 1440'),
    'WallC-phone.dc.html': (PHONE_C, 'C · next 6, then the list · living street · 390'),
}

def board(cfg):
    phone = cfg['phone']
    w, h = (390, 844) if phone else (1440, 1260)
    if phone:
        top = f'<img src="{AS("phone-top.jpg")}" alt="Events header and filters (as in the mock)" style="display:block;width:390px;height:166px">'
        if cfg['mode'] == 'walk':
            under = f'<div style="margin:8px 19px 0">{ruler_html(True)}</div><p style="margin:4px 16px 0;color:#6e6657;font-size:12px;text-align:center">Drag the wall through the day</p>'
        else:
            pd = ('<sc-for list="{{pdots}}" as="d" hint-placeholder-count="7"><button type="button" aria-label="{{d.label}}" onClick="{{d.pick}}" style="display:grid;place-items:center;width:24px;height:44px;padding:0;border:0;background:none;cursor:pointer"><i style="display:block;height:8px;border-radius:4px;{{d.style}}"></i></button></sc-for>' if cfg.get('dots') else '')
            under = f'<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin:6px 16px 0;min-height:44px">{pd}<strong style="font-size:13px;white-space:nowrap">{H("pageLabel")}</strong>{"" if cfg.get("dots") else chr(60) + "span style=" + chr(34) + "color:#6e6657;font-size:12px" + chr(34) + ">· swipe for the next posters" + chr(60) + "/span>"}</div>'
            if cfg.get('ruler'):
                under = f'<div style="margin:8px 19px 0">{ruler_html(True)}</div>' + under
        content = top + card(cfg) + under + list_html(True)
        chrome = f'<div aria-hidden="true" style="position:absolute;left:0;right:0;bottom:0;height:61px;background:url({AS("phone-bar.jpg")}) 0 0 / 390px 61px no-repeat, #fffdf8;z-index:20"></div>'
    else:
        top = f'<img src="{AS("desk-top.jpg")}" alt="Events header and filters (as in the mock)" style="display:block;width:1440px;height:144px">'
        under = '' if not cfg.get('ruler', True) else f'<div style="margin:14px 32px 0;max-width:1361px">{ruler_html(False)}<div style="margin-top:4px;color:#6e6657;font-size:12px">The whole day: one bar per hour; the hours on the wall in teal. Tap an hour to walk there; the sky follows. The bars show the placeholder day.</div></div>'
        content = top + '<div style="height:8px"></div>' + card(cfg) + under + list_html(False)
        chrome = ''
    jcfg = {k: cfg[k] for k in ('phone', 'mode', 'W', 'SKY') if k in cfg}
    jcfg.update({k: cfg[k] for k in ('zoneW', 'bayW', 'px', 'pw', 'ph', 'rows', 'per', 'cols', 'pool', 'stepBays', 'spots', 'nowPill') if k in cfg})
    jcfg['col'] = {'pitch': 240, 'pw': 226, 'ph': 316, 'inset': 19}
    logic = LOGIC.replace('__CFG__', json.dumps(jcfg)).replace('__AMAP__', json.dumps(AMAP)).replace('__STYLES__', json.dumps(STYLES))
    props = {"skyAt": {"editor": "enum", "options": ["wall", "dawn", "morning", "now", "sunset", "dusk", "night"], "default": "wall", "section": "Review"},
             "weather": {"editor": "enum", "options": ["forecast", "clear", "partly", "cloudy", "showers", "rain", "mist"], "default": "forecast", "section": "Review"},
             "$preview": {"width": w, "height": h}}
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Events · {cfg['option']} · {'390' if phone else '1440'} · living street</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">
<style>{css(cfg['W'], cfg['p'])}</style>
</helmet>
<div style="width:{w}px;height:{h}px;position:relative;overflow:hidden;background:#faf6ee;color:#211d15;font-family:Geist, system-ui, sans-serif;font-size:15px;line-height:1.4">
<div style="position:absolute;inset:0;overflow-y:auto;overflow-x:hidden;scroll-behavior:smooth">
{content}
</div>
{chrome}
</div>
</x-dc>
<script type="text/x-dc" data-dc-script data-props='{json.dumps(props)}'>
{logic}
</script>
</body>
</html>
'''


if __name__ == '__main__':
    sample = AMAP.get('poster-day-rings.png', 'assets/poster-day-rings.png')
    open(os.path.join(PROJ, 'WallPoster.dc.html'), 'w').write(POSTER.replace('__SAMPLE__', sample))
    for name, (cfg, title) in BOARDS.items():
        open(os.path.join(PROJ, name), 'w').write(board(cfg))
    idx_path = os.path.join(PROJ, 'canvas.json')
    idx = json.load(open(idx_path))
    notes = {'D': 'Wall · D (recommended) · a living street: the sky, weather and light follow the hour you walk to',
             'A': 'Wall · A · a wall you walk along through the day, hour by hour',
             'B': 'Wall · B · a paged wall, six posters at a time',
             'C': 'Wall · C · the next six from now, then the list'}
    y = 6100
    for opt in 'DABC':
        idx['notes']['wall' + opt] = {'x': 0, 'y': y - 240, 'text': notes[opt], 'kind': 'title1', 'maxW': 2380}
        for dev, x, w, h in (('desktop', 0, 1440, 1260), ('phone', 1520, 390, 844)):
            name = f'Wall{opt}-{dev}.dc.html'
            idx['boards'][name] = {'x': x, 'y': y, 'w': w, 'h': h, 'title': BOARDS[name][1], 'is_interactive': True}
            if name not in idx['order']:
                idx['order'].append(name)
        y += 1260 + 360
    json.dump(idx, open(idx_path, 'w'), indent=2, ensure_ascii=False)
    print('ok')
