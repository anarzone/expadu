"""The Stories board's working chrome: header, filters, date picker, saved
panel, popovers, the phone's bottom bar and the richer details."""
from scene_art import H

LOGO = ('<svg width="30" height="30" viewBox="13 22 74 60" aria-hidden="true"><g transform="translate(2.45 2.05)">'
        '<circle cx="20.5" cy="50" r="6" fill="#05badd"/><rect x="30" y="24" width="13" height="52" rx="6" fill="#211d15"/>'
        '<rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/>'
        '<rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/>'
        '<rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/></g></svg>')

ICON = {
    'home': '<path d="m3.5 10.5 8.5-7 8.5 7"/><path d="M5.5 9v11h13V9"/>',
    'cal': '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4M16 3v4M3.5 10.5h17"/>',
    'doc': '<path d="M14 3H6.5v18h11V6.5L14 3Z"/><path d="M14 3v3.5h3.5M9 11h6M9 14.5h6M9 18h3.5"/>',
    'tram': '<rect x="5" y="3.5" width="14" height="14" rx="3"/><path d="M5 11h14M9 21l1.5-3.5M15 21l-1.5-3.5"/>',
    'grid': '<rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.8"/>',
    'pin': '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11Z"/><circle cx="12" cy="10" r="2.3"/>',
    'down': '<path d="m7 10 5 5 5-5"/>',
    'bookmark': '<path d="M6.5 3.5h11v17l-5.5-3.8-5.5 3.8v-17Z"/>',
    'search': '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/>',
    'x': '<path d="M6 6l12 12M18 6 6 18"/>',
    'culture': '<path d="M3.5 9 12 4.5 20.5 9M5.5 9v9M9.5 9v9M14.5 9v9M18.5 9v9M3.5 20h17"/>',
    'games': '<rect x="4" y="4" width="16" height="16" rx="3.5"/><circle cx="9" cy="9" r="1.2" fill="currentColor"/><circle cx="15" cy="15" r="1.2" fill="currentColor"/><circle cx="15" cy="9" r="1.2" fill="currentColor"/><circle cx="9" cy="15" r="1.2" fill="currentColor"/>',
    'outdoors': '<path d="M5 19c0-8 6-14 14-14 0 8-6 14-14 14Z"/><path d="M5 19l8-8"/>',
    'language': '<path d="M4 5h16v10H9.5L4 19.5V5Z"/><path d="M8 9h8M8 12h5"/>',
    'all': '<rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.8"/>',
}


def ic(name, size=20, colour='currentColor'):
    return f'<svg width="{size}" height="{size}" viewBox="0 0 24 24" fill="none" stroke="{colour}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{ICON[name]}</svg>'


NAV = [('Today', 'home', '#00758c'), ('Events', 'cal', '#7047ac'), ('Paperwork', 'doc', '#b94b18'), ('Travel', 'tram', '#00758c'), ('Services', 'grid', '#7047ac')]
PILL = 'display:flex;align-items:center;gap:7px;height:44px;padding:0 14px;border:1px solid #e6ddcb;border-radius:14px;background:#fffdf8;color:#211d15;font:inherit;font-size:14.5px;cursor:pointer'
ICONBTN = 'position:relative;display:grid;place-items:center;width:44px;height:44px;border:0;border-radius:14px;background:none;color:#211d15;cursor:pointer'
BADGE = f'<sc-if value="{H("hasSavedN")}" hint-placeholder-val="{{{{ false }}}}"><b style="position:absolute;right:4px;top:4px;display:grid;place-items:center;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#c23b0e;color:#fff;font-size:11px">{H("savedN")}</b></sc-if>'
DATE = 'display:flex;align-items:center;gap:6px;height:44px;padding:0 14px;border:0;background:#ffe45c;transform:rotate(-1.5deg);box-shadow:0 2px 0 #00000014;font-family:\'Permanent Marker\',cursive;font-size:16px;color:#211d15;cursor:pointer;white-space:nowrap'


def nav_tabs():
    out = []
    for name, icon, colour in NAV:
        on = name == 'Events'
        out.append(f'<button type="button" {"aria-current=\"page\" " if on else ""}onClick="{H("nav" + name)}" style="display:flex;align-items:center;gap:7px;height:44px;padding:0 14px;border:0;border-radius:12px;background:{"#def5f8" if on else "none"};color:#211d15;font:inherit;font-size:15px;font-weight:{600 if on else 500};cursor:pointer">{ic(icon, 20, colour)}{name}</button>')
    return ''.join(out)


def cat_tabs(phone):
    cats = [('all', 'All events', 'all', '#00566a'), ('Culture', 'Culture', 'culture', '#4a2bc0'), ('Games', 'Games', 'games', '#211d15'), ('Outdoors', 'Outdoors', 'outdoors', '#2f8a4a'), ('Language', 'Language', 'language', '#7047ac')]
    btns = ''.join(
        f'<button type="button" aria-pressed="{H("catOn." + cid)}" onClick="{H("catPick." + cid)}" style="flex:none;display:flex;align-items:center;gap:7px;height:{40 if phone else 44}px;padding:0 {13 if phone else 14}px;border:{"1px solid #e6ddcb" if phone else "0"};border-radius:{999 if phone else 12}px;font:inherit;font-size:14.5px;cursor:pointer;{H("catStyle." + cid)}">{ic(icon, 18, colour)}{label}</button>'
        for cid, label, icon, colour in cats)
    return f'''<div role="group" aria-label="Category" style="display:flex;gap:{6 if phone else 4}px;{'overflow-x:auto;scrollbar-width:none;padding:0 12px' if phone else ''}">{btns}</div>'''


def search_box(width):
    return f'''<label style="{PILL};width:{width};cursor:text;padding-right:6px">{ic('search', 18)}<input type="text" role="searchbox" aria-label="Search events" placeholder="Search events" value="{H('q')}" onInput="{H('onQ')}" style="flex:1;min-width:0;height:40px;border:0;background:none;font:inherit;font-size:14.5px;color:#211d15;outline:none"/>
  <sc-if value="{H('hasQ')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" aria-label="Clear search" onClick="{H('clearQ')}" style="display:grid;place-items:center;width:32px;height:32px;border:0;border-radius:8px;background:#efe7d6;cursor:pointer">{ic('x', 14)}</button></sc-if></label>'''


def desk_header():
    return f'''<header style="display:flex;align-items:center;gap:24px;height:72px;padding:0 32px;background:#fffdf8;border-bottom:1px solid #e6ddcb">
  <span style="display:flex;align-items:center;gap:6px;font-size:24px;font-weight:700;letter-spacing:-1px">{LOGO}Expadu</span>
  <nav aria-label="Main navigation" style="display:flex;gap:6px;margin:0 auto">{nav_tabs()}</nav>
  <button type="button" onClick="{H('openCity')}" style="{PILL};border-radius:999px">{ic('pin', 18)}Cologne{ic('down', 14)}</button>
  <button type="button" aria-label="Saved" onClick="{H('openSaved')}" style="{ICONBTN}">{ic('bookmark', 21)}{BADGE}</button>
  <button type="button" aria-label="Your account" onClick="{H('openAccount')}" style="display:grid;place-items:center;width:40px;height:40px;border:0;border-radius:50%;background:#e7e2f6;color:#4b3d8a;font:inherit;font-weight:700;cursor:pointer">A</button>
</header>
<div style="display:flex;align-items:center;gap:10px;padding:16px 32px 4px">
  <button type="button" onClick="{H('openDate')}" style="{DATE}">{H('dateLabel')}{ic('down', 14)}</button>
  {search_box('260px')}
  <button type="button" aria-pressed="{H('savedOnly')}" aria-label="Only saved" onClick="{H('toggleSavedOnly')}" style="{PILL};width:44px;padding:0;justify-content:center;{H('savedOnlyStyle')}">{ic('bookmark', 19)}</button>
  <span style="width:1px;height:28px;background:#e6ddcb;margin:0 4px"></span>
  {cat_tabs(False)}
</div>
{filter_note('2px 32px 0')}'''


def phone_header():
    return f'''<header style="display:flex;align-items:center;gap:4px;height:54px;padding:0 8px 0 14px;background:#fffdf8;border-bottom:1px solid #e6ddcb">
  <span style="display:flex;align-items:center;gap:5px;margin-right:auto;font-size:21px;font-weight:700;letter-spacing:-.8px">{LOGO}Expadu</span>
  <button type="button" onClick="{H('openCity')}" style="display:flex;align-items:center;gap:3px;height:44px;padding:0 6px;border:0;background:none;color:#6e6657;font:inherit;font-size:14px;cursor:pointer">Cologne{ic('down', 13)}</button>
  <button type="button" aria-label="Saved" onClick="{H('openSaved')}" style="{ICONBTN}">{ic('bookmark', 21)}{BADGE}</button>
  <button type="button" aria-label="Your account" onClick="{H('openAccount')}" style="display:grid;place-items:center;width:36px;height:36px;margin-left:2px;border:0;border-radius:50%;background:#e7e2f6;color:#4b3d8a;font:inherit;font-weight:700;cursor:pointer">A</button>
</header>
<div style="display:flex;align-items:center;gap:8px;padding:10px 12px 8px">
  <button type="button" onClick="{H('openDate')}" style="{DATE};height:42px;font-size:14.5px">{H('dateLabel')}{ic('down', 12)}</button>
  {search_box('auto;flex:1;min-width:0;height:42px')}
  <button type="button" aria-pressed="{H('savedOnly')}" aria-label="Only saved" onClick="{H('toggleSavedOnly')}" style="{PILL};width:42px;height:42px;padding:0;justify-content:center;{H('savedOnlyStyle')}">{ic('bookmark', 18)}</button>
</div>
{cat_tabs(True)}
{filter_note('6px 14px 0')}'''


def filter_note(pad):
    return f'''<sc-if value="{H('filtering')}" hint-placeholder-val="{{{{ false }}}}"><div style="display:flex;align-items:center;gap:10px;padding:{pad};font-size:13px;color:#4d4639"><strong>{H('filterCount')}</strong><button type="button" onClick="{H('clearAll')}" style="height:32px;padding:0 10px;border:0;border-radius:999px;background:#efe7d6;color:#211d15;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer">Clear filters</button></div></sc-if>'''


def empty_state():
    return f'''<sc-if value="{H('noMatch')}" hint-placeholder-val="{{{{ false }}}}">
    <div style="position:absolute;left:50%;top:46%;transform:translate(-50%,-50%);z-index:8;width:min(320px,86%);padding:18px 20px;border-radius:18px;background:#fffdf8f2;box-shadow:0 18px 40px -16px #0007;text-align:center">
      <strong style="display:block;font-size:18px">{H('emptyTitle')}</strong>
      <span style="display:block;margin-top:4px;color:#6e6657;font-size:13.5px">{H('emptyText')}</span>
      <button type="button" onClick="{H('emptyAction')}" style="margin-top:12px;height:44px;padding:0 18px;border:0;border-radius:12px;background:#211d15;color:#fffdf8;font:inherit;font-weight:700;cursor:pointer">{H('emptyButton')}</button>
    </div>
  </sc-if>'''


SAVED_THUMB = f'<button type="button" onClick="{H("it.open")}" aria-label="{H("it.alt")}" style="position:relative;flex:none;width:60px;height:84px;padding:0;border:0;background:none;cursor:pointer"><dc-import name="WallPoster" p="{H("it")}" hint-size="100%,100%"></dc-import><span aria-hidden="true" style="position:absolute;inset:0;z-index:3"></span></button>'


def popovers(phone):
    """Date, city, account, saved and the other sections; tap outside to close."""
    sheet = 'position:absolute;left:12px;right:12px;bottom:72px' if phone else 'position:absolute'
    card = 'z-index:56;padding:16px 18px;border-radius:18px;background:#fffdf8;box-shadow:0 18px 44px -14px rgba(20,12,4,.5);font-size:14px;line-height:1.4;color:#211d15'
    where = (lambda desk: sheet if phone else f'position:absolute;{desk}')
    poster = SAVED_THUMB
    return f'''<sc-if value="{H('popOpen')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" aria-label="Close" onClick="{H('closePop')}" style="position:absolute;inset:0;z-index:55;border:0;background:{'rgba(20,16,30,.28)' if phone else 'transparent'};cursor:default"></button></sc-if>
<sc-if value="{H('popDate')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Choose a day" style="{where('left:32px;top:134px;width:420px')};{card}">
    <strong style="display:block;font-size:16px">This week</strong>
    <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:10px">
      <sc-for list="{H('days')}" as="d" hint-placeholder-count="7"><button type="button" aria-pressed="{H('d.on')}" onClick="{H('d.pick')}" style="display:flex;flex-direction:column;align-items:center;gap:2px;min-height:58px;padding:6px 0;border:1px solid #e6ddcb;border-radius:12px;font:inherit;cursor:pointer;{H('d.style')}"><small style="font-size:11.5px">{H('d.wd')}</small><strong style="font-size:17px">{H('d.n')}</strong><i style="width:6px;height:6px;border-radius:50%;{H('d.dot')}"></i></button></sc-for>
    </div>
    <p style="margin:10px 0 0;color:#6e6657;font-size:12.5px">Only Wed 30 Sept has a sample programme in this preview.</p>
  </div>
</sc-if>
<sc-if value="{H('popCity')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="City" style="{where('right:120px;top:64px;width:300px')};{card}"><strong style="display:flex;align-items:center;gap:8px;font-size:16px">{ic('pin', 18)}Cologne</strong><p style="margin:6px 0 0;color:#6e6657">Expadu starts with Cologne. Other cities come later.</p></div>
</sc-if>
<sc-if value="{H('popAccount')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Your account" style="{where('right:24px;top:64px;width:280px')};{card}"><strong style="display:block;font-size:16px">Your account</strong><p style="margin:6px 0 0;color:#6e6657">Anar · Preview profile</p><p style="margin:4px 0 0;color:#6e6657">Account settings are in the main app.</p></div>
</sc-if>
<sc-if value="{H('popSaved')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="Saved" style="{where('right:70px;top:64px;width:420px')};{card}">
    <div style="display:flex;align-items:center;justify-content:space-between"><strong style="font-size:16px">{H('savedTitle')}</strong><button type="button" aria-label="Close" onClick="{H('closePop')}" style="width:40px;height:40px;border:0;background:none;font-size:22px;cursor:pointer">×</button></div>
    <sc-if value="{H('noSaved')}" hint-placeholder-val="{{{{ true }}}}"><p style="margin:4px 0 0;color:#6e6657">Nothing saved yet. Tap a poster, then Save, or Save inside a story.</p></sc-if>
    <div style="display:flex;flex-direction:column;gap:8px;max-height:{300 if phone else 420}px;overflow-y:auto;margin-top:6px">
      <sc-for list="{H('savedItems')}" as="it" hint-placeholder-count="2">
        <div style="display:flex;align-items:center;gap:12px">{poster}<span style="flex:1;min-width:0"><strong style="display:block">{H('it.cap2')}</strong><small style="color:#6e6657">{H('it.cap1')}</small></span><button type="button" aria-label="Remove from saved" onClick="{H('it.remove')}" style="display:grid;place-items:center;width:40px;height:40px;border:0;border-radius:10px;background:#efe7d6;cursor:pointer">{ic('x', 15)}</button></div>
      </sc-for>
    </div>
  </div>
</sc-if>
<sc-if value="{H('hasToast')}" hint-placeholder-val="{{{{ false }}}}"><div role="status" style="position:absolute;left:50%;{'bottom:76px' if phone else 'top:84px'};transform:translateX(-50%);z-index:57;max-width:90%;padding:10px 16px;border-radius:12px;background:#211d15;color:#fffdf8;font-size:13.5px;text-align:center;box-shadow:0 10px 24px -10px #000a">{H('toast')}</div></sc-if>'''


def bottom_bar():
    tabs = ''.join(
        f'<button type="button" {"aria-current=\"page\" " if n == "Events" else ""}onClick="{H("nav" + n)}" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:48px;border:0;background:none;color:{"#00566a" if n == "Events" else "#211d15"};font:inherit;font-size:11.5px;font-weight:{700 if n == "Events" else 500};cursor:pointer"><span style="display:grid;place-items:center;width:52px;height:30px;border-radius:15px;background:{"#def5f8" if n == "Events" else "none"}">{ic(i, 21, "currentColor")}</span>{n}</button>'
        for n, i, _ in NAV)
    return f'<nav aria-label="Sections" style="position:absolute;left:0;right:0;bottom:0;z-index:50;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));padding:6px 6px 8px;background:#fffdf8f5;border-top:1px solid #e6ddcb">{tabs}</nav>'


def details(phone):
    """The poster you tapped: thumbnail, facts, the hour's sample weather,
    Save, Watch this hour and (for real events) Plan the way."""
    return f'''<sc-if value="{H('hasPanel')}" hint-placeholder-val="{{{{ false }}}}">
  <div role="dialog" aria-label="{H('panel.title')}" style="position:absolute;{'left:12px;right:12px;bottom:72px' if phone else 'right:56px;top:360px;width:560px'};z-index:45;display:flex;gap:14px;padding:14px;border-radius:20px;background:#fffdf8;box-shadow:0 18px 40px -14px rgba(20,12,4,.55);font-size:14px;line-height:1.35">
    <div style="position:relative;flex:none;width:{84 if phone else 110}px;height:{118 if phone else 154}px"><dc-import name="WallPoster" p="{H('panel.p')}" hint-size="100%,100%"></dc-import></div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:flex-start;gap:8px">
        <strong style="flex:1;display:block;font-size:17px;letter-spacing:-.3px">{H('panel.title')}</strong>
        <button type="button" aria-label="Close" onClick="{H('closePanel')}" style="display:grid;place-items:center;width:40px;height:40px;margin:-8px -6px 0 0;border:0;border-radius:12px;background:none;cursor:pointer;font-size:22px;color:#211d15">×</button>
      </div>
      <span style="display:block;color:#4d4639">{H('panel.line')}</span>
      <span style="display:flex;align-items:center;gap:6px;margin-top:4px;color:#6e6657;font-size:12.5px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6e6657" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{H('panel.wIcon')}"/></svg>{H('panel.weather')}</span>
      <span style="display:block;margin-top:2px;color:#6e6657;font-size:12.5px">{H('panel.note')}</span>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
        <button type="button" aria-pressed="{H('panel.savePressed')}" onClick="{H('panel.save')}" style="height:42px;padding:0 15px;border:1.5px solid #211d15;border-radius:12px;background:#fffdf8;font:inherit;font-weight:600;cursor:pointer">{H('panel.saveLabel')}</button>
        <button type="button" onClick="{H('panel.watch')}" style="display:flex;align-items:center;gap:7px;height:42px;padding:0 15px;border:0;border-radius:12px;background:#211d15;color:#fffdf8;font:inherit;font-weight:600;cursor:pointer"><i style="display:block;width:14px;height:14px;border-radius:50%;background:conic-gradient(from 200deg,#f2c94c,#ef7d2a,#c23b0e,#e8cc6e,#f2c94c)"></i>Watch this hour</button>
        <sc-if value="{H('panel.showPlan')}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{H('panel.plan')}" style="height:42px;padding:0 15px;border:0;border-radius:12px;background:#c23b0e;color:#fff;font:inherit;font-weight:600;cursor:pointer">Plan the way</button></sc-if>
      </div>
      <sc-if value="{H('panel.planOpen')}" hint-placeholder-val="{{{{ false }}}}"><p style="margin:8px 0 0;padding:8px 10px;border-radius:10px;background:#def5f8;color:#00566a;font-size:12.5px">{H('panel.planText')}</p></sc-if>
    </div>
  </div>
</sc-if>'''


LOGIC = r'''
  match(e) {
    const s = this.state || {};
    if (s.day && s.day !== '2026-09-30') return false;
    if (s.cat && s.cat !== 'all' && this.catOf(e) !== s.cat) return false;
    if (s.savedOnly && !(s.saved || {})[e.n]) return false;
    const q = (s.q || '').trim().toLowerCase();
    if (q) {
      const two = (h) => (h < 10 ? '0' : '') + h;
      const hay = [e.title, this.catOf(e), e.place || '', e.ph ? 'placeholder layout #' + e.n : 'sample', two(e.h) + ':00', e.time || ''].join(' ').toLowerCase();
      if (q.split(/\s+/).some((w) => hay.indexOf(w) < 0)) return false;
    }
    return true;
  }
  // After a filter changes, the wall walks to the first hour that matches.
  jumpToFirst() {
    const c = this.cfg();
    if (c.mode !== 'walk') return;
    const { ev } = this.events();
    const hits = ev.filter((e) => this.match(e));
    const first = hits.find((e) => e.h >= 13) || hits[0];
    if (!first) return;
    const its = this.items();
    const stops = this.stops();
    this.setState({ offset: Math.min(its.find((i) => i.h === first.h).x, stops[stops.length - 1]), drag: 0 });
  }
  storyList(h) {
    const { ev } = this.events();
    return ev.filter((e) => e.h === h && this.match(e));
  }
  chrome(v) {
    const c = this.cfg();
    const s = Object.assign({ cat: 'all', q: '', savedOnly: false, day: '2026-09-30', pop: '', saved: {}, toast: '' }, this.state || {});
    const { ev, two } = this.events();
    const go = (patch) => () => this.setState(patch);
    v.catOn = {}; v.catPick = {}; v.catStyle = {};
    ['all', 'Culture', 'Games', 'Outdoors', 'Language'].forEach((id) => {
      const on = s.cat === id;
      v.catOn[id] = on;
      v.catPick[id] = () => this.setState({ cat: id, page: undefined, story: null }, () => this.jumpToFirst());
      v.catStyle[id] = on ? 'background:#def5f8;color:#211d15;font-weight:600;border-color:#bfe6ec' : 'background:' + (c.phone ? '#fffdf8' : 'none') + ';color:#211d15;font-weight:500';
    });
    v.q = s.q; v.hasQ = !!s.q;
    v.onQ = (e) => this.setState({ q: e.target.value, page: undefined }, () => this.jumpToFirst());
    v.clearQ = () => this.setState({ q: '', page: undefined, offset: undefined });
    v.savedOnly = !!s.savedOnly;
    v.savedOnlyStyle = s.savedOnly ? 'background:#def5f8;border-color:#bfe6ec;color:#00566a' : '';
    v.toggleSavedOnly = () => this.setState({ savedOnly: !s.savedOnly, page: undefined }, () => this.jumpToFirst());
    const DAYS = [['Mon', 28], ['Tue', 29], ['Wed', 30], ['Thu', 1], ['Fri', 2], ['Sat', 3], ['Sun', 4]];
    const iso = (d) => (d > 20 ? '2026-09-' : '2026-10-0') + d;
    const cur = DAYS.find(([, d]) => iso(d) === s.day) || DAYS[2];
    v.dateLabel = cur[0] + ' ' + cur[1] + (cur[1] > 20 ? ' Sept' : ' Oct');
    v.days = DAYS.map(([wd, d]) => ({ wd, n: d, on: iso(d) === s.day, pick: go({ day: iso(d), pop: '', page: undefined, offset: undefined }),
      style: iso(d) === s.day ? 'background:#211d15;color:#fffdf8;border-color:#211d15' : 'background:#fffdf8;color:#211d15',
      dot: d === 30 ? 'background:#c23b0e' : 'background:transparent' }));
    // Pop-overs: one at a time; tapping outside closes.
    v.popOpen = !!s.pop; v.closePop = go({ pop: '' });
    v.popDate = s.pop === 'date'; v.popCity = s.pop === 'city'; v.popAccount = s.pop === 'account'; v.popSaved = s.pop === 'saved';
    v.openDate = go({ pop: s.pop === 'date' ? '' : 'date' }); v.openCity = go({ pop: s.pop === 'city' ? '' : 'city' });
    v.openAccount = go({ pop: s.pop === 'account' ? '' : 'account' }); v.openSaved = go({ pop: s.pop === 'saved' ? '' : 'saved' });
    const toast = (t) => { clearTimeout(this._toastT); this.setState({ toast: t }); this._toastT = setTimeout(() => this.setState({ toast: '' }), 2800); };
    ['Today', 'Paperwork', 'Travel', 'Services'].forEach((n) => { v['nav' + n] = () => toast(n + ' is its own page in the mock. This board shows Events.'); });
    v.navEvents = () => { const el = document.querySelector('#wall'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    v.hasToast = !!s.toast; v.toast = s.toast;
    // Saved.
    const savedList = ev.filter((e) => s.saved[e.n]);
    v.savedN = savedList.length; v.hasSavedN = savedList.length > 0; v.noSaved = !savedList.length;
    v.savedTitle = savedList.length ? 'Saved · ' + savedList.length : 'Saved';
    v.savedItems = savedList.map((e) => Object.assign({}, e, { w: 60, h: 84, saved: false, open: () => this.setState({ sel: e.n, pop: '', plan: false }),
      cap1: e.ph ? two(e.h) + ':00 hour · not a real event' : e.time + ' · ' + e.place, cap2: e.ph ? 'Layout placeholder #' + e.n : e.title,
      remove: () => { const sv = Object.assign({}, s.saved); delete sv[e.n]; this.setState({ saved: sv }); } }));
    // What the filters leave.
    const matching = ev.filter((e) => this.match(e));
    v.filtering = s.cat !== 'all' || !!s.q.trim() || s.savedOnly;
    v.filterCount = matching.length + (matching.length === 1 ? ' event matches' : ' events match');
    v.clearAll = go({ cat: 'all', q: '', savedOnly: false, page: undefined, offset: undefined });
    const otherDay = s.day !== '2026-09-30';
    v.noMatch = otherDay || !matching.length;
    v.emptyTitle = otherDay ? 'No sample programme for ' + v.dateLabel : 'Nothing matches';
    v.emptyText = otherDay ? 'This preview has one sample day, with 100 events.' : (s.savedOnly && !savedList.length ? 'You haven’t saved anything yet.' : 'Try another word or category.');
    v.emptyButton = otherDay ? 'Back to Wed 30 Sept' : 'Clear filters';
    v.emptyAction = otherDay ? go({ day: '2026-09-30', page: undefined }) : v.clearAll;
    // The details: thumbnail, the hour's sample weather and Watch this hour.
    if (v.hasPanel) {
      const e = ev.find((x) => x.n === s.sel);
      const k = this.sky(e.h + 0.5);
      const list = this.storyList(e.h);
      v.panel.p = Object.assign({}, e, { saved: !!s.saved[e.n] });
      v.panel.weather = k.temp + ' ' + k.name + ' at ' + two(e.h) + ':00 · sample weather';
      v.panel.wIcon = k.icon;
      v.panel.watch = () => { const sn = Object.assign({}, s.seen); sn[e.h] = true; this.setState({ story: { h: e.h, i: Math.max(0, list.indexOf(e)), t: Date.now() }, seen: sn, sel: 0 }); };
    } else {
      v.panel.p = ev[0]; v.panel.weather = ''; v.panel.wIcon = ''; v.panel.watch = () => {};
    }
    return v;
  }
'''
