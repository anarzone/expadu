"""Stories on the Events mock's real catalogue: the two sample events on
Wed 30 Sept and the seven captured listings of Tue 8 Sept (already
happened), from connected/sample-day.js and connected/model.js. Tapping an
event opens the mock's details dialog (About · Plan · Get there)."""
import json
from scene_art import H
import stories_ui as SU

SERIES = lambda date, page: {'aboutSource': f'City listing for {date} in the same series',
                             'sourceUrl': f'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/{page}/index.html'}
CATALOGUE = [
    dict(id='today-review-culture', title='Street culture night', date='2026-09-30', start='18:30', end='21:00', venue='Rheinauhafen', area='Neustadt-Süd',
         category='Culture', price=None, attendancePolicy='fixed', captured='Sample programme', poster='poster-street.png',
         description='An illustrative evening of music by the Rhine. Sample event for this design preview.'),
    dict(id='today-review-cinema', title='Cinema under the stars', date='2026-09-30', start='20:00', end='22:00', venue='Stadtgarten', area='Neustadt-Nord',
         category='Culture', price=None, attendancePolicy='fixed', captured='Sample programme', poster='poster-cinema.png', outdoors=True,
         description='An illustrative outdoor film night. Sample event for this design preview.'),
    dict(id='exhibition-ludwig', title='HIER UND JETZT im Museum Ludwig', titleEnglish='Here and now at Museum Ludwig', date='2026-09-08', start='10:00', end='18:00',
         venue='Museum Ludwig', area='Altstadt-Nord', category='Culture', price=None, attendancePolicy='window', captured='8 September 2026', poster='real-hier-und-jetzt-im-museum.png'),
    dict(id='kunst-machen', title='Kunst zum Mit-Machen', titleEnglish='Art to join in with', date='2026-09-08', start='16:00', end='17:30', venue='Stadtteilbibliothek Mülheim',
         area='Mülheim', category='Culture', price='Free', attendancePolicy='unknown', captured='8 September 2026', poster='real-kunst-zum-mit-machen.png',
         about='Look at artworks and make your own, with Eva Degenhardt from the children’s art project KunstPostStation.', **SERIES('13 October 2026', 41191)),
    dict(id='schach', title='SchachXperten', titleEnglish='Chess experts', date='2026-09-08', start='16:00', end='17:30', venue='Stadtteilbibliothek Chorweiler', area='Chorweiler',
         category='Games', price='Free', attendancePolicy='unknown', captured='8 September 2026', poster='real-schachxperten.png', ages='8 and up',
         about='Play and learn chess, from age 8.', **SERIES('6 October 2026', 41126)),
    dict(id='forest', title='Den Forst erleben – Klimawandel hautnah', titleEnglish='Experience the forest: climate change up close', date='2026-09-08', start='17:00', end='19:00',
         venue='Waldlabor', area='Junkersdorf', category='Outdoors', price='Free', attendancePolicy='unknown', captured='8 September 2026', poster='real-den-forst-erleben-klimaw.png'),
    dict(id='garden', title='Botanischer Feierabendrundgang', titleEnglish='After-work tour of the Botanical Garden', date='2026-09-08', start='18:00', end='20:00',
         venue='Flora und Botanischer Garten', area='Riehl', category='Outdoors', price='Free · donations welcome', attendancePolicy='unknown', captured='8 September 2026',
         poster='real-botanischer-feierabendru.png', about='Garden lovers and anyone curious about botany discover the garden’s many plants and animals on a 45-minute short tour.',
         **SERIES('6 October 2026', 41411)),
    dict(id='tandem', title='Tandem Language Café', date='2026-09-08', start='18:30', end='20:00', venue='VHS Studienhaus am Neumarkt', area='Altstadt-Nord',
         category='Language', price='Free', attendancePolicy='unknown', captured='8 September 2026', poster='real-tandem-language-caf.png'),
    dict(id='film', title='Alpen Film Fest Filmtour 2026', titleEnglish='Alpine Film Festival tour 2026', date='2026-09-08', start='19:00', end=None, venue='Open Air Kino Köln',
         area='Altstadt-Süd', category='Culture', price=None, attendancePolicy='unknown', captured='8 September 2026', poster='real-alpen-film-fest-filmtour.png'),
]

LOGIC = r'''
  today() { return '2026-09-30'; }
  day() { return (this.state && this.state.day) || this.today(); }
  isPast() { return this.day() < this.today(); }
  events() {
    if (this._evr) return this._evr;
    const two = (h) => (h < 10 ? '0' : '') + h;
    const ev = (__CATALOGUE__).map((x, i) => Object.assign({}, x, {
      n: i + 1, h: parseInt(x.start, 10), ph: false, time: x.start, until: x.end ? 'until ' + x.end : 'end not listed',
      place: x.venue, price: x.price || 'Price not listed', cat: x.category, src: this.asset(x.poster), tag: '',
      alt: x.title + ', ' + x.start + ', ' + x.venue,
    }));
    this._evr = { ev, COUNTS: {}, two };
    return this._evr;
  }
  catOf(e) { return e.category; }
  match(e) {
    const s = this.state || {};
    if (e.date !== this.day()) return false;
    if (s.cat && s.cat !== 'all' && e.category !== s.cat) return false;
    if (s.free && !String(e.price || '').startsWith('Free')) return false;
    if (s.area && e.area !== s.area) return false;
    if (s.savedOnly && !(s.saved || {})[e.n]) return false;
    const q = (s.q || '').trim().toLowerCase();
    if (q) {
      const hay = [e.title, e.titleEnglish || '', e.venue, e.area, e.category].join(' ').toLowerCase();
      if (q.split(/\s+/).some((w) => hay.indexOf(w) < 0)) return false;
    }
    return true;
  }
  // The wall walks the hours that have events.
  items() {
    const c = this.cfg();
    const { ev } = this.events();
    const hours = Array.from(new Set(ev.filter((e) => this.match(e)).map((e) => e.h))).sort((a, b) => a - b);
    let x = 0;
    const list = hours.map((h) => { const it = { h, w: c.bayW, x }; x += c.bayW; return it; });
    this._total = x;
    return list;
  }
  nowX() {
    const its = this.items();
    if (!its.length || this.isPast()) return 0;
    const it = its.find((i) => i.h >= 13) || its[its.length - 1];
    const stops = this.stops();
    return Math.min(it.x, stops[stops.length - 1]);
  }
  nowPage() {
    const c = this.cfg();
    if (c.pool !== 'day' || this.isPast()) return 0;
    const i = this.pool().findIndex((e) => e.h >= 13);
    return i < 0 ? 0 : Math.floor(i / c.per);
  }
  colList() { const { ev } = this.events(); return ev.filter((e) => e.date === this.day()); }
  colPrefix() { return this.isPast() ? 'Tue 8 Sept' : 'Tonight'; }
  storyHours() {
    const { ev } = this.events();
    const hours = Array.from(new Set(ev.filter((e) => this.match(e)).map((e) => e.h))).sort((a, b) => a - b);
    return this.isPast() ? hours : hours.filter((h) => h >= 13).concat(hours.filter((h) => h < 13));
  }
  storyList(h) { const { ev } = this.events(); return ev.filter((e) => e.h === h && this.match(e)); }
  jumpToFirst() {
    const c = this.cfg();
    if (c.mode !== 'walk') return;
    this.setState({ offset: undefined, drag: 0 });
  }
  // The details dialog, as on the Events page (event-sheet.js).
  details(v, s) {
    const { ev, two } = this.events();
    const e = ev.find((x) => x.n === s.sel);
    v.hasDet = !!e;
    if (!e) { v.det = { tabAbout: true }; return; }
    const past = e.date < this.today();
    const go = (patch) => () => this.setState(patch);
    const mins = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
    const gap = mins(e.start) - mins('14:30');
    const gapText = Math.floor(gap / 60) + (Math.floor(gap / 60) === 1 ? ' hour' : ' hours') + (gap % 60 ? ' ' + (gap % 60) + ' min' : '');
    const notListed = [!e.price || e.price === 'Price not listed' ? 'admission' : '', 'language', e.ages ? '' : 'who it’s for', 'accessibility'].filter(Boolean);
    const words = (xs) => xs.length < 2 ? xs.join('') : xs.slice(0, -1).join(', ') + ' and ' + xs[xs.length - 1];
    const tab = s.tab || 'about';
    const planned = !!(s.planned || {})[e.n];
    const remind = !!(s.remind || {})[e.n];
    const dayLabel = past ? 'Tue, 8 Sept 2026' : 'Wed, 30 Sept 2026';
    const k = this.sky(e.h + 0.5);
    v.det = {
      title: e.title, english: e.titleEnglish || '', hasEnglish: !!e.titleEnglish, cat: e.category.toUpperCase(),
      tape: past ? 'Past · 8 Sept' : 'Tonight · 30 Sept', tapeStyle: past ? 'background:#fffdf8;color:#211d15' : 'background:#ffe36e;color:#211d15',
      big: e.start, until: e.end ? 'until ' + e.end : 'end not listed', src: e.src,
      tabAbout: tab === 'about', tabPlan: tab === 'plan', tabGet: tab === 'get',
      aboutStyle: tab === 'about' ? 'background:#fffdf8;box-shadow:0 2px 6px -3px #0004;font-weight:700' : '',
      planStyle: tab === 'plan' ? 'background:#fffdf8;box-shadow:0 2px 6px -3px #0004;font-weight:700' : '',
      getStyle: tab === 'get' ? 'background:#fffdf8;box-shadow:0 2px 6px -3px #0004;font-weight:700' : '',
      toAbout: go({ tab: 'about' }), toPlan: go({ tab: 'plan' }), toGet: go({ tab: 'get' }),
      rain: !past && !!e.outdoors && k.rain > 0.3, rainText: 'Rain likely at ' + e.start.slice(0, 2) + ':00 · sample forecast',
      fit: past ? 'Already happened. Captured for Tuesday 8 September 2026.' : 'It starts ' + gapText + ' after your Blue Card appointment ends.',
      fitDot: past ? '#b9ab90' : '#00a0b5',
      venue: e.venue, area: e.area,
      about: e.description || e.about || '', hasAbout: !!(e.description || e.about), aboutSource: e.aboutSource || '', hasAboutSource: !!e.aboutSource,
      arrive: { fixed: 'By the start time', window: 'Any time while it’s open' }[e.attendancePolicy] || 'Not listed',
      admission: e.price && e.price !== 'Price not listed' ? e.price : '', hasAdmission: !!(e.price && e.price !== 'Price not listed'),
      ages: e.ages ? 'Ages ' + e.ages : '', hasAges: !!e.ages,
      notListed: 'Not listed: ' + words(notListed) + '. Check with the organiser before you go.',
      remindStyle: remind ? 'background:#c23b0e' : 'background:#e6ddcb', knobStyle: remind ? 'transform:translateX(18px)' : '', remindOn: remind,
      remind: () => { const r = Object.assign({}, s.remind); r[e.n] = !remind; this.setState({ remind: r }); },
      listingOpen: !!s.listing, toggleListing: go({ listing: !s.listing }),
      listingTag: past ? 'Captured' : 'Sample',
      listingText: past ? 'From the City of Cologne’s events calendar, captured on 8 September 2026. English titles are translations.' : 'A fictional sample event for this design preview. No live listing.',
      source: e.sourceUrl || '', hasSource: !!e.sourceUrl,
      // Plan.
      month: 'SEPT', dnum: past ? '8' : '30', dayLabel, start: e.start, end: e.end || 'Not listed', past,
      duration: e.end ? (() => { const d = mins(e.end) - mins(e.start); return (Math.floor(d / 60) ? Math.floor(d / 60) + ' hr' : '') + (d % 60 ? ' ' + (d % 60) + ' min' : ''); })() : 'Length not listed',
      yourDay: past ? 'This already happened' : 'Blue Card appointment 14:00–14:30' + (Object.keys(s.planned || {}).length ? ' · ' + Object.keys(s.planned || {}).length + ' event' + (Object.keys(s.planned || {}).length === 1 ? '' : 's') + ' planned' : ''),
      planned, notPlanned: !planned && !past, canPlan: !past,
      plan: () => { const p = Object.assign({}, s.planned); if (p[e.n]) delete p[e.n]; else p[e.n] = true; this.setState({ planned: p }); },
      planLabel: planned ? 'In your plan' : 'Add to plan', planBtnStyle: planned ? 'background:#fffdf8;color:#00566a;border:1.5px solid #00566a' : 'background:#c23b0e;color:#fff;border:0',
      // Get there.
      from: past ? 'Not available for a past event' : 'After Blue Card appointment, which ends at 14:30', to: e.venue, arriveBy: 'Arrive ' + e.start,
      openPlanner: () => { clearTimeout(this._toastT); this.setState({ toast: 'Travel is its own page in the mock. This board shows Events.' }); this._toastT = setTimeout(() => this.setState({ toast: '' }), 2800); },
      // Footer.
      saved: !!(s.saved || {})[e.n], saveLabel: (s.saved || {})[e.n] ? 'Saved' : 'Save for later',
      saveStyle: (s.saved || {})[e.n] ? 'background:#211d15;color:#fffdf8' : 'background:#fffdf8;color:#211d15',
      save: () => { const sv = Object.assign({}, s.saved); if (sv[e.n]) delete sv[e.n]; else sv[e.n] = true; this.setState({ saved: sv }); },
      close: go({ sel: 0, tab: 'about', plan: false, listing: false }),
    };
  }
  chrome(v) {
    const c = this.cfg();
    const s = Object.assign({ cat: 'all', q: '', savedOnly: false, free: false, area: '', pop: '', saved: {}, toast: '' }, this.state || {});
    const { ev, two } = this.events();
    const go = (patch) => () => this.setState(patch);
    v.catOn = {}; v.catPick = {}; v.catStyle = {};
    ['all', 'Culture', 'Games', 'Outdoors', 'Language'].forEach((id) => {
      const on = s.cat === id;
      v.catOn[id] = on;
      v.catPick[id] = () => this.setState({ cat: id, page: undefined, offset: undefined, story: null });
      v.catStyle[id] = on ? 'background:#def5f8;color:#211d15;font-weight:600;border-color:#bfe6ec' : 'background:' + (c.phone ? '#fffdf8' : 'none') + ';color:#211d15;font-weight:500';
    });
    v.q = s.q; v.hasQ = !!s.q;
    v.onQ = (e) => this.setState({ q: e.target.value, page: undefined, offset: undefined });
    v.clearQ = go({ q: '', page: undefined, offset: undefined });
    v.savedOnly = !!s.savedOnly;
    v.savedOnlyStyle = s.savedOnly ? 'background:#def5f8;border-color:#bfe6ec;color:#00566a' : '';
    v.toggleSavedOnly = go({ savedOnly: !s.savedOnly, page: undefined, offset: undefined });
    v.free = !!s.free;
    v.freeStyle = s.free ? 'background:#def5f8;border-color:#bfe6ec;color:#00566a;font-weight:600' : '';
    v.toggleFree = go({ free: !s.free, page: undefined, offset: undefined });
    // Areas on the chosen day.
    const areas = Array.from(new Set(ev.filter((e) => e.date === this.day()).map((e) => e.area))).sort();
    v.areaLabel = s.area || 'Area';
    v.areaStyle = s.area ? 'background:#def5f8;border-color:#bfe6ec;color:#00566a;font-weight:600' : '';
    v.areas = [''].concat(areas).map((a) => ({ label: a || 'Any area', on: s.area === a, style: s.area === a ? 'background:#def5f8;font-weight:700' : '',
      pick: go({ area: a, pop: '', page: undefined, offset: undefined }) }));
    v.popArea = s.pop === 'area'; v.openArea = go({ pop: s.pop === 'area' ? '' : 'area' });
    // The calendar: September 2026; days with listings are marked.
    const counts = {};
    ev.forEach((e) => { counts[e.date] = (counts[e.date] || 0) + 1; });
    v.month = [];
    for (let i = 0; i < 1; i++) v.month.push({ blank: true, n: '' });
    for (let d = 1; d <= 30; d++) {
      const iso = '2026-09-' + (d < 10 ? '0' : '') + d;
      const on = iso === this.day();
      const has = counts[iso] || 0;
      v.month.push({ blank: false, n: d, on, has: !!has, aria: d + ' September' + (has ? ', ' + has + ' events' : ''),
        style: on ? 'background:#211d15;color:#fffdf8' : iso === this.today() ? 'box-shadow:inset 0 0 0 1.5px #211d15' : '',
        dot: has ? (iso < this.today() ? 'background:#b9ab90' : 'background:#c23b0e') : 'background:transparent',
        pick: go({ day: iso, pop: '', page: undefined, offset: undefined, pos: 0, sel: 0 }) });
    }
    const d = new Date(this.day() + 'T12:00:00');
    v.dateLabel = d.toLocaleDateString('en-GB', { weekday: 'short' }) + ' ' + d.getDate() + ' ' + (d.getMonth() === 8 ? 'Sept' : 'Oct');
    v.popOpen = !!s.pop; v.closePop = go({ pop: '' });
    v.popDate = s.pop === 'date'; v.popCity = s.pop === 'city'; v.popAccount = s.pop === 'account'; v.popSaved = s.pop === 'saved';
    v.openDate = go({ pop: s.pop === 'date' ? '' : 'date' }); v.openCity = go({ pop: s.pop === 'city' ? '' : 'city' });
    v.openAccount = go({ pop: s.pop === 'account' ? '' : 'account' }); v.openSaved = go({ pop: s.pop === 'saved' ? '' : 'saved' });
    const toast = (t) => this.mxToast(t);
    ['Today', 'Paperwork', 'Travel', 'Services'].forEach((n) => { v['nav' + n] = () => toast(n + ' is its own page in the mock. This board shows Events.'); });
    v.navEvents = () => { const el = document.querySelector('#wall'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    v.hasToast = !!s.toast; v.toast = s.toast;
    const savedList = ev.filter((e) => s.saved[e.n]);
    v.savedN = savedList.length; v.hasSavedN = savedList.length > 0; v.noSaved = !savedList.length;
    v.savedTitle = savedList.length ? 'Saved · ' + savedList.length : 'Saved';
    v.savedItems = savedList.map((e) => Object.assign({}, e, { w: 60, h: 84, saved: false, art: this.mxArt(e), open: () => { this.setState({ pop: '', day: e.date }); this.mxOpen(e, 'about'); },
      cap1: (e.date < this.today() ? 'Tue 8 Sept' : 'Wed 30 Sept') + ' · ' + e.start + ' · ' + e.venue, cap2: e.title,
      remove: () => { const sv = Object.assign({}, s.saved); delete sv[e.n]; this.setState({ saved: sv }); } }));
    const dayList = ev.filter((e) => e.date === this.day());
    const matching = dayList.filter((e) => this.match(e));
    v.filtering = s.cat !== 'all' || !!s.q.trim() || s.savedOnly || s.free || !!s.area;
    v.filterCount = matching.length + ' of ' + dayList.length + (dayList.length === 1 ? ' event' : ' events');
    v.clearAll = go({ cat: 'all', q: '', savedOnly: false, free: false, area: '', page: undefined, offset: undefined });
    v.past = this.isPast() && dayList.length > 0;
    v.toToday = go({ day: this.today(), page: undefined, offset: undefined, pos: 0 });
    const noDay = !dayList.length;
    v.noMatch = noDay || !matching.length;
    v.emptyTitle = noDay ? 'No events captured for this day.' : 'Nothing matches';
    v.emptyText = noDay ? 'Wed 30 Sept has the sample programme; Tue 8 Sept has a captured one that has already happened.' : 'Try another word, category or area.';
    v.emptyButton = noDay ? 'View Wed 30 Sept' : 'Clear filters';
    v.emptyAction = noDay ? v.toToday : v.clearAll;
    // Past days have no weather on record.
    if (this.isPast()) { v.k.temp = ''; v.k.name = 'Weather not recorded'; v.k.chipNote = 'Tue 8 Sept'; v.k.when = '8 Sept'; }
    this.mxSheet(v, s);
    return v;
  }
'''


def date_pop(phone):
    card = 'z-index:56;padding:16px 18px;border-radius:18px;background:#fffdf8;box-shadow:0 18px 44px -14px rgba(20,12,4,.5);font-size:14px;line-height:1.4;color:#211d15'
    where = 'position:absolute;left:12px;right:12px;bottom:72px' if phone else 'position:absolute;left:32px;top:134px;width:400px'
    head = ''.join(f'<span style="text-align:center;font-size:11.5px;color:#6e6657">{d}</span>' for d in ('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'))
    return f'''<sc-if value="{H('popDate')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Choose a day" style="{where};{card}">
    <strong style="display:block;font-size:16px">September 2026</strong>
    <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;margin-top:10px">{head}
      <sc-for list="{H('month')}" as="d" hint-placeholder-count="30">
        <sc-if value="{H('d.blank')}" hint-placeholder-val="{{{{ false }}}}"><span></span></sc-if>
        <sc-if value="{H('d.n')}" hint-placeholder-val="{{{{ true }}}}"><button type="button" aria-label="{H('d.aria')}" aria-pressed="{H('d.on')}" onClick="{H('d.pick')}" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;height:44px;border:0;border-radius:10px;background:none;font:inherit;font-size:14px;cursor:pointer;{H('d.style')}">{H('d.n')}<i style="width:5px;height:5px;border-radius:50%;{H('d.dot')}"></i></button></sc-if>
      </sc-for>
    </div>
    <p style="margin:10px 0 0;color:#6e6657;font-size:12.5px"><i style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#c23b0e;margin-right:6px"></i>Sample programme · <i style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#b9ab90;margin:0 6px 0 4px"></i>Captured, already happened</p>
  </div>
</sc-if>'''


def area_pop(phone):
    card = 'z-index:56;padding:12px;border-radius:18px;background:#fffdf8;box-shadow:0 18px 44px -14px rgba(20,12,4,.5);font-size:14px;color:#211d15'
    where = 'position:absolute;left:12px;right:12px;bottom:72px' if phone else 'position:absolute;right:32px;top:134px;width:280px'
    return f'''<sc-if value="{H('popArea')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Area" style="{where};{card}">
    <strong style="display:block;padding:4px 8px 8px;font-size:16px">Area</strong>
    <sc-for list="{H('areas')}" as="a" hint-placeholder-count="4"><button type="button" aria-pressed="{H('a.on')}" onClick="{H('a.pick')}" style="display:flex;align-items:center;width:100%;min-height:44px;padding:0 10px;border:0;border-radius:10px;background:none;font:inherit;text-align:left;cursor:pointer;{H('a.style')}">{H('a.label')}</button></sc-for>
  </div>
</sc-if>'''


def past_note(pad):
    return f'''<sc-if value="{H('past')}" hint-placeholder-val="{{{{ false }}}}"><div role="note" style="display:flex;align-items:center;gap:12px;margin:{pad};padding:10px 14px;border-radius:14px;background:#efe7d6;color:#4d4639;font-size:13px">{SU.ic('cal', 18)}<span style="flex:1"><strong>Already happened.</strong> These listings were captured for Tuesday 8 September. You can look at them, but not plan them.</span><button type="button" onClick="{H('toToday')}" style="flex:none;height:36px;padding:0 12px;border:0;border-radius:10px;background:#fffdf8;color:#c23b0e;font:inherit;font-weight:700;cursor:pointer">See Wed 30 Sept</button></div></sc-if>'''


TAG = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 12.5V4.5h8l9 9-8 8-9-9Z"/><circle cx="8" cy="9" r="1.4"/></svg>'
MAP = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/></svg>'


def desk_header():
    h = SU.desk_header()
    # Free and Area join the filters (the catalogue lists prices and areas).
    h = h.replace(f'''  <span style="width:1px;height:28px;background:#e6ddcb;margin:0 4px"></span>''',
                  f'''  <button type="button" aria-pressed="{H('free')}" onClick="{H('toggleFree')}" style="{SU.PILL};{H('freeStyle')}">{TAG}Free</button>
  <span style="width:1px;height:28px;background:#e6ddcb;margin:0 4px"></span>''')
    h = h.replace(f'''{SU.filter_note('2px 32px 0')}''', f'''{SU.filter_note('2px 32px 0')}{past_note('6px 32px 0')}''')
    h = h.replace('''  {cat_tabs(False)}''', '''  {cat_tabs(False)}''')
    # Area at the end of the filter row.
    return h.replace('</div>\n<sc-if value="{{filtering}}"', f'''  <button type="button" onClick="{H('openArea')}" style="{SU.PILL};margin-left:auto;{H('areaStyle')}">{MAP}{H('areaLabel')}{SU.ic('down', 14)}</button>
</div>
<sc-if value="{{{{filtering}}}}"''', 1)


def phone_header():
    h = SU.phone_header()
    extra = (f'<button type="button" aria-pressed="{H("free")}" onClick="{H("toggleFree")}" style="flex:none;display:flex;align-items:center;gap:6px;height:40px;padding:0 13px;border:1px solid #e6ddcb;border-radius:999px;background:#fffdf8;font:inherit;font-size:14.5px;cursor:pointer;{H("freeStyle")}">{TAG}Free</button>'
             f'<button type="button" onClick="{H("openArea")}" style="flex:none;display:flex;align-items:center;gap:6px;height:40px;padding:0 13px;border:1px solid #e6ddcb;border-radius:999px;background:#fffdf8;font:inherit;font-size:14.5px;cursor:pointer;{H("areaStyle")}">{MAP}{H("areaLabel")}{SU.ic("down", 13)}</button>')
    h = h.replace('Language</button></div>', 'Language</button>' + extra + '</div>', 1)
    return h + past_note('8px 12px 0')


def details_dialog(phone):
    """The Events page's details dialog: banner, About · Plan · Get there,
    Save for later and Add to plan."""
    shell = ('position:absolute;left:0;right:0;bottom:0;max-height:88%;border-radius:22px 22px 0 0' if phone
             else 'position:absolute;left:calc(50% - 290px);top:150px;width:580px;max-height:900px;border-radius:22px')
    tabbtn = 'flex:1;display:flex;align-items:center;justify-content:center;gap:7px;height:46px;border:0;border-radius:12px;background:none;color:#211d15;font:inherit;font-size:15px;cursor:pointer'
    row = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 2px;border-top:1px solid #efe7d6'
    info = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/></svg>'
    route = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9"/></svg>'
    return f'''<sc-if value="{H('hasDet')}" hint-placeholder-val="{{{{ false }}}}">
  <button type="button" aria-label="Close details" onClick="{H('det.close')}" style="position:absolute;inset:0;z-index:58;border:0;background:rgba(20,16,30,.45);cursor:default"></button>
  <div role="dialog" aria-label="{H('det.title')}" style="{shell};z-index:59;display:flex;flex-direction:column;background:#faf6ee;box-shadow:0 30px 70px -20px #000a;overflow:hidden">
    <div style="display:flex;align-items:flex-start;gap:12px;padding:18px 16px 10px 20px">
      <div style="flex:1;min-width:0"><h2 style="margin:0;font-size:{21 if phone else 23}px;letter-spacing:-.5px;line-height:1.15">{H('det.title')}</h2><sc-if value="{H('det.hasEnglish')}" hint-placeholder-val="{{{{ false }}}}"><span style="display:block;margin-top:2px;color:#6e6657;font-size:13.5px">{H('det.english')}</span></sc-if></div>
      <button type="button" aria-label="Close" onClick="{H('det.close')}" style="display:grid;place-items:center;width:44px;height:44px;border:0;border-radius:12px;background:none;cursor:pointer">{SU.ic('x', 22)}</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:0 18px 12px">
      <div style="position:relative;height:{150 if phone else 176}px;border-radius:16px;overflow:hidden;background:#1d1745">
        <div aria-hidden="true" style="position:absolute;inset:-30px;background:url({H('det.src')}) center 40% / cover no-repeat;filter:blur(18px) saturate(1.25);transform:scale(1.15)"></div>
        <div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(20,14,40,.62),rgba(20,14,40,.28) 60%,rgba(20,14,40,.18))"></div>
        <div style="position:absolute;left:16px;bottom:16px;color:#fff"><span style="display:block;font-size:12px;font-weight:700;letter-spacing:.06em">{H('det.cat')}</span><span style="display:inline-block;margin-top:6px;padding:3px 10px;font-family:'Permanent Marker',cursive;font-size:15px;transform:rotate(-2deg);{H('det.tapeStyle')}">{H('det.tape')}</span></div>
        <div style="position:absolute;right:16px;bottom:14px;color:#fff;text-align:right"><span style="display:block;font-size:{40 if phone else 46}px;font-weight:300;line-height:1;color:transparent;-webkit-text-stroke:1.4px #fff;font-variant-numeric:tabular-nums">{H('det.big')}</span><small style="font-size:12.5px">{H('det.until')}</small></div>
      </div>
      <div role="tablist" style="display:flex;gap:4px;margin-top:12px;padding:4px;border-radius:16px;background:#efe7d6">
        <button type="button" role="tab" aria-selected="{H('det.tabAbout')}" onClick="{H('det.toAbout')}" style="{tabbtn};{H('det.aboutStyle')}">{info}About</button>
        <button type="button" role="tab" aria-selected="{H('det.tabPlan')}" onClick="{H('det.toPlan')}" style="{tabbtn};{H('det.planStyle')}">{SU.ic('cal', 18)}Plan</button>
        <button type="button" role="tab" aria-selected="{H('det.tabGet')}" onClick="{H('det.toGet')}" style="{tabbtn};{H('det.getStyle')}">{route}Get there</button>
      </div>
      <sc-if value="{H('det.tabAbout')}" hint-placeholder-val="{{{{ true }}}}">
        <div style="padding-top:12px;font-size:14.5px;line-height:1.45">
          <sc-if value="{H('det.rain')}" hint-placeholder-val="{{{{ false }}}}"><span style="display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border:1.5px solid #bfe6ec;border-radius:999px;color:#00566a;font-size:13px;font-weight:600"><svg width="14" height="14" viewBox="0 0 24 24" fill="#00a0b5" aria-hidden="true"><path d="M12 3.5s6 6.4 6 10.5a6 6 0 0 1-12 0c0-4.1 6-10.5 6-10.5Z"/></svg>{H('det.rainText')}</span></sc-if>
          <p style="display:flex;gap:10px;margin:10px 0 0;color:#4d4639"><i style="flex:none;width:9px;height:9px;margin-top:6px;border-radius:50%;background:{H('det.fitDot')}"></i>{H('det.fit')}</p>
          <button type="button" onClick="{H('det.toGet')}" style="display:flex;align-items:center;gap:14px;width:100%;margin-top:12px;padding:14px;border:1px solid #e6ddcb;border-radius:16px;background:#fffdf8;font:inherit;text-align:left;cursor:pointer"><span style="display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:#efe7d6">{SU.ic('pin', 20)}</span><span style="flex:1"><strong style="display:block;font-size:16px">{H('det.venue')}</strong><small style="color:#6e6657;font-size:13px">{H('det.area')}</small></span>{SU.ic('down', 18)}</button>
          <sc-if value="{H('det.hasAbout')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:12px 0 0;color:#4d4639">{H('det.about')}</p></sc-if>
          <sc-if value="{H('det.hasAboutSource')}" hint-placeholder-val="{{{{ false }}}}"><small style="display:block;margin-top:4px;color:#6e6657">{H('det.aboutSource')}</small></sc-if>
          <div style="margin-top:10px">
            <div style="{row}"><span style="color:#4d4639">When to arrive</span><strong style="font-weight:600">{H('det.arrive')}</strong></div>
            <sc-if value="{H('det.hasAdmission')}" hint-placeholder-val="{{{{ false }}}}"><div style="{row}"><span style="color:#4d4639">Admission</span><strong style="font-weight:600">{H('det.admission')}</strong></div></sc-if>
            <sc-if value="{H('det.hasAges')}" hint-placeholder-val="{{{{ false }}}}"><div style="{row}"><span style="color:#4d4639">Who it’s for</span><strong style="font-weight:600">{H('det.ages')}</strong></div></sc-if>
          </div>
          <p style="margin:8px 0 0;padding-top:10px;border-top:1px solid #efe7d6;color:#4d4639;font-size:13.5px">{H('det.notListed')}</p>
          <div style="display:flex;align-items:center;gap:12px;margin-top:12px;padding:12px 14px;border:1px solid #e6ddcb;border-radius:16px;background:#fffdf8">
            <span style="display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#efe7d6"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16Zm4 4h4"/></svg></span>
            <span style="flex:1"><strong style="display:block">Remind me before it starts</strong><small style="color:#6e6657">Reminders aren’t sent in this preview</small></span>
            <button type="button" role="switch" aria-checked="{H('det.remindOn')}" aria-label="Remind me before it starts" onClick="{H('det.remind')}" style="position:relative;width:44px;height:26px;border:0;border-radius:13px;cursor:pointer;{H('det.remindStyle')}"><i style="position:absolute;left:3px;top:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:transform .2s;{H('det.knobStyle')}"></i></button>
          </div>
          <button type="button" aria-expanded="{H('det.listingOpen')}" onClick="{H('det.toggleListing')}" style="display:flex;align-items:center;gap:10px;width:100%;min-height:44px;margin-top:6px;padding:0 2px;border:0;background:none;font:inherit;color:#4d4639;cursor:pointer">{info}Listing details <span style="padding:1px 8px;border-radius:6px;background:#e7e2f6;color:#4b3d8a;font-size:12px;font-weight:600">{H('det.listingTag')}</span><span style="margin-left:auto">{SU.ic('down', 16)}</span></button>
          <sc-if value="{H('det.listingOpen')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:0;padding:0 2px 6px;color:#6e6657;font-size:13px">{H('det.listingText')}<sc-if value="{H('det.hasSource')}" hint-placeholder-val="{{{{ false }}}}"><span style="display:block;margin-top:4px;word-break:break-all">Source: {H('det.source')}</span></sc-if></p></sc-if>
        </div>
      </sc-if>
      <sc-if value="{H('det.tabPlan')}" hint-placeholder-val="{{{{ false }}}}">
        <div style="padding-top:14px;font-size:14.5px">
          <div style="display:flex;align-items:center;gap:14px">
            <span style="display:grid;place-items:center;width:58px;height:62px;border-radius:14px;background:#fffdf8;border:1px solid #e6ddcb;line-height:1.05;text-align:center"><small style="font-size:11px;font-weight:700;color:#c23b0e">{H('det.month')}</small><strong style="font-size:24px">{H('det.dnum')}</strong></span>
            <span><small style="color:#6e6657">Event time</small><strong style="display:block;font-size:16px">{H('det.dayLabel')}</strong></span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:12px;padding:12px 14px;border-radius:14px;background:#fffdf8;border:1px solid #e6ddcb"><span><small style="color:#6e6657">Starts</small><strong style="display:block;font-size:18px">{H('det.start')}</strong></span><span style="color:#b9ab90">–</span><span><small style="color:#6e6657">Ends</small><strong style="display:block;font-size:18px">{H('det.end')}</strong></span><span style="margin-left:auto;color:#6e6657;font-size:13px">{H('det.duration')}</span></div>
          <div style="margin-top:12px;padding:12px 14px;border-radius:14px;background:#efe7d6"><small style="color:#6e6657">Your day</small><strong style="display:block">{H('det.yourDay')}</strong></div>
          <sc-if value="{H('det.canPlan')}" hint-placeholder-val="{{{{ true }}}}"><p style="margin:12px 0 0;color:#4d4639">Plan only · admission isn’t booked.</p></sc-if>
          <sc-if value="{H('det.past')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:12px 0 0;color:#4d4639">This already happened, so it can’t be planned.</p></sc-if>
        </div>
      </sc-if>
      <sc-if value="{H('det.tabGet')}" hint-placeholder-val="{{{{ false }}}}">
        <div style="padding-top:14px;font-size:14.5px">
          <div style="padding:12px 14px;border-radius:14px;background:#fffdf8;border:1px solid #e6ddcb"><small style="color:#6e6657">From</small><strong style="display:block">{H('det.from')}</strong><div style="height:1px;margin:10px 0;background:#efe7d6"></div><small style="color:#6e6657">To</small><strong style="display:block">{H('det.to')}</strong></div>
          <div style="display:flex;gap:10px;margin-top:10px"><span style="flex:1;padding:10px 12px;border-radius:12px;background:#efe7d6"><small style="color:#6e6657">Day</small><strong style="display:block">{H('det.dayLabel')}</strong></span><span style="flex:1;padding:10px 12px;border-radius:12px;background:#efe7d6"><small style="color:#6e6657">Arrival</small><strong style="display:block">{H('det.arriveBy')}</strong></span></div>
          <div style="margin-top:12px;padding:12px 14px;border-radius:14px;background:#def5f8;color:#00566a"><strong style="display:block">Get there with Departures</strong><span style="font-size:13px">Live routes aren’t connected in this preview. The full planner has clearly marked sample journeys.</span></div>
          <button type="button" onClick="{H('det.openPlanner')}" style="margin-top:10px;height:44px;padding:0 16px;border:1.5px solid #211d15;border-radius:12px;background:#fffdf8;font:inherit;font-weight:600;cursor:pointer">Open planner</button>
        </div>
      </sc-if>
    </div>
    <div style="display:flex;align-items:center;gap:10px;padding:12px 18px {18 if phone else 16}px;border-top:1px solid #e6ddcb;background:#faf6ee">
      <button type="button" aria-pressed="{H('det.saved')}" aria-label="{H('det.saveLabel')}" onClick="{H('det.save')}" style="display:flex;align-items:center;gap:8px;height:48px;padding:0 16px;border:1.5px solid #211d15;border-radius:14px;font:inherit;font-weight:600;cursor:pointer;{H('det.saveStyle')}">{SU.ic('bookmark', 19)}{H('det.saveLabel')}</button>
      <sc-if value="{H('det.canPlan')}" hint-placeholder-val="{{{{ true }}}}"><button type="button" onClick="{H('det.plan')}" style="margin-left:auto;display:flex;align-items:center;gap:8px;height:48px;padding:0 20px;border-radius:14px;font:inherit;font-weight:700;cursor:pointer;{H('det.planBtnStyle')}">{SU.ic('cal', 19)}{H('det.planLabel')}</button></sc-if>
    </div>
  </div>
</sc-if>'''


CAT_JSON = json.dumps(CATALOGUE, ensure_ascii=False)
