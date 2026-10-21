'use strict';

const concept = new URLSearchParams(location.search).get('concept') === 'refined' ? 'refined' : 'fresh';
document.body.classList.add(concept);
document.querySelector(`#choose-${concept}`).setAttribute('aria-current', 'page');
document.title = `Expadu Today — ${concept === 'fresh' ? 'Fresh direction' : 'Refined familiar'}`;

const paths = {
  home: '<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
  pin: '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
  bell: '<path d="M18 8a6 6 0 0 0-12 0c0 8-3 8-3 10h18c0-2-3-2-3-10Z"/><path d="M10 21h4"/>',
  train: '<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/><circle cx="8.5" cy="15" r=".5"/><circle cx="15.5" cy="15" r=".5"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h3m3 0h2"/>',
  document: '<path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/>',
  bag: '<rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V5a4 4 0 0 1 8 0v2M3 13h18m-11 0v3h4v-3"/>',
  search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
  arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
  down: '<path d="m6 9 6 6 6-6"/>',
  right: '<path d="m9 5 7 7-7 7"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  check: '<path d="m5 12 4 4L19 6"/>',
  close: '<path d="m6 6 12 12M6 18 18 6"/>',
  heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
  leaf: '<path d="M20 3C8 2 3 7 4 13c1 7 11 8 14 1 1-3 2-7 2-11Z"/><path d="M3 22c3-8 6-11 11-14"/>',
  people: '<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4m2-16a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v2"/>',
  culture: '<path d="m2 8 10-5 10 5H2Zm2 13h16M5 10v8m5-8v8m4-8v8m5-8v8M3 18h18"/>',
  play: '<path d="m3 21 6-17h6l6 17M6 12h12m-8 0v5h4v-5"/>',
  sport: '<circle cx="12" cy="12" r="9"/><path d="m9 8 6 0 2 5-5 4-5-4 2-5ZM9 8 7 5m8 3 2-3m0 8 4 1m-14-1-4 1m9 3v4"/>',
  table: '<path d="M3 8h18v8H3zM12 5v11M5 16v5m14-5v5M2 11h20"/>',
  waves: '<path d="M2 6q3-3 6 0t6 0 8 0M2 12q3-3 6 0t6 0 8 0M2 18q3-3 6 0t6 0 8 0"/>',
  route: '<circle cx="5" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 5h9a4 4 0 0 1 0 8H8a3 3 0 0 0 0 6h9"/>',
  grid: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
};
const icon = (name, className = '') => `<svg viewBox="0 0 24 24" aria-hidden="true" class="${className}">${paths[name] || paths.pin}</svg>`;
const safe = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
const brand = `<span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>expadu</span>`;

const items = [
  { id: 'green', title: 'Grünzug Seeberg', location: 'Seeberg', category: 'Green space', type: 'outdoors', image: 'riverside.png', icon: 'leaf', heading: 'A little room to breathe', description: 'Make space for a green stop in your day.', detail: 'Explore this place, save it for later, or add it to the day you are putting together. Opening hours, access and travel time are not verified in this prototype.' },
  { id: 'night', title: 'Newcomers’ Night', location: 'Cologne', category: 'Meet people', type: 'culture', image: 'gathering.png', icon: 'people', heading: 'A new city. A few new faces.', description: 'Something social for your Saturday shortlist.', detail: 'A sample event used to explore the design. An actual event would show its organiser, verified date, venue and source here.' },
  { id: 'table', title: 'Tischtennisplatte', location: 'Merkenich', category: 'Table tennis', type: 'outdoors', icon: 'table', detail: 'Place details would appear here with verified information and source links. This prototype does not claim equipment availability, access or current conditions.' },
  { id: 'pitch', title: 'Bolzplatz', location: 'Chorweiler', category: 'Football', type: 'outdoors', icon: 'sport', detail: 'A football-place example from the current discovery content. Add it to your sample day or save it for later.' },
  { id: 'playground', title: 'Spielplatz', location: 'Merkenich', category: 'Playground', type: 'kids', icon: 'play', detail: 'A playground category does not establish age suitability, supervision or accessibility. Those facts need their own verified records.' },
  { id: 'family-pitch', title: 'Bolzplatz', location: 'Fühlingen', category: 'Football', type: 'kids', icon: 'sport', detail: 'An example from the current family discovery rail. Suitability and access would be verified before making a specific recommendation.' },
];
const findItem = (id) => items.find((item) => item.id === id);
const state = { filter: 'all', plan: [], saved: new Set(), daySaved: false, notifications: false };
const navButton = (destination, label, glyph, active = false) => `<button type="button" data-nav="${destination}"${active ? ' class="active" aria-current="page"' : ''}>${glyph ? icon(glyph) : ''}${label}</button>`;
const utilityButton = (destination, glyph, label, extra = '') => `<button type="button" class="icon-button ${extra}" data-nav="${destination}" aria-label="${label}">${icon(glyph)}</button>`;

function contextLinks() {
  return `<button type="button" class="context-link weather" data-nav="weather">${icon('sun')}<strong>20°</strong> Partly cloudy</button><button type="button" class="context-link" data-nav="departures">${icon('train')}Getting around ${icon('right', 'chevron')}</button><button type="button" class="context-link" data-nav="river">${icon('waves')}Rhine conditions ${icon('right', 'chevron')}</button>`;
}
function paperwork() {
  return `<section class="admin-nudge" aria-labelledby="paperwork-title">${icon('document')}<div class="nudge-copy"><h2 id="paperwork-title">Your paperwork checklist</h2><p>Review your saved tasks</p></div><button type="button" class="text-button" data-nav="bureaucracy"><span>Open checklist</span>${icon('arrow')}</button></section>`;
}
function filters() {
  return `<div class="filter-row" role="group" aria-label="Filter discoveries">${[['all', 'For you', ''], ['outdoors', 'Outdoors', 'leaf'], ['kids', 'With kids', 'play'], ['culture', 'Culture & people', 'culture']].map(([id, text, glyph]) => `<button type="button" class="filter" data-filter="${id}" aria-pressed="${state.filter === id}">${glyph ? icon(glyph) : ''}${text}</button>`).join('')}</div>`;
}
function footer() {
  return `<section class="notification-strip" id="notification-strip" aria-label="Notification preference">${icon('bell')}<p>Keep useful city updates close.</p><button type="button" class="text-button" data-action="notify">Enable notifications</button><button type="button" class="icon-button" data-action="dismiss-notify" aria-label="Dismiss notification suggestion">${icon('close')}</button></section><footer class="body-footer"><span>Cologne, a little more familiar.</span><span>Design prototype · Sample content</span></footer>`;
}
function discoverSections() {
  return `<section aria-labelledby="discovery-title"><div class="discovery-head"><div><h2 id="discovery-title">${concept === 'fresh' ? 'Something good for today' : 'Make the most of your Saturday'}</h2><p>A green space, a new face, a little time outside.</p></div>${filters()}</div><div class="feature-grid" id="featured-places"></div></section><section class="page-section" aria-labelledby="nearby-title"><div class="section-heading"><div><h2 id="nearby-title">Around your neighbourhood</h2><p>Small discoveries, close to everyday life.</p></div><button type="button" class="text-button" data-nav="places">Explore places ${icon('arrow')}</button></div><div class="place-list" id="nearby-places"></div></section><section class="page-section kids-strip" aria-labelledby="kids-title"><div class="kids-title"><h2 id="kids-title">With the kids</h2><p>A little fresh air together.</p></div>${miniCard(findItem('playground'))}${miniCard(findItem('family-pitch'))}</section><section class="explore-shortcuts" aria-label="City services"><h2>Your city, at hand</h2><button data-nav="departures" type="button">${icon('train')}Departures</button><button data-nav="events" type="button">${icon('calendar')}Events</button><button data-nav="bureaucracy" type="button">${icon('document')}Bureaucracy</button><button data-nav="services" type="button">${icon('bag')}Services</button></section>`;
}

function freshPage() {
  return `<header class="top-nav"><a href="?concept=fresh" class="brand" aria-label="Expadu Today">${brand}</a><nav class="primary-nav" aria-label="Main navigation">${navButton('today', 'Today', '', true)}${navButton('places', 'Explore', '')}${navButton('city', 'Get around', '')}${navButton('bureaucracy', 'Life admin', '')}</nav><div class="nav-utilities">${utilityButton('search', 'search', 'Search places and events')}${utilityButton('alerts', 'bell', 'View alerts')}${utilityButton('menu', 'grid', 'Open all services', 'mobile-more')}<button type="button" class="avatar" data-nav="profile" aria-label="Anar’s account">A</button></div></header><main id="main" class="main"><section class="welcome"><div><p class="hello">Good afternoon, Anar</p><h1>Your Saturday in Cologne</h1></div><button type="button" class="location" data-nav="location">${icon('pin')}<span>Merkenich</span>${icon('down')}</button></section><div class="context-line" aria-label="City context">${contextLinks()}</div>${paperwork()}${discoverSections()}${footer()}</main>`;
}
function refinedPage() {
  return `<div class="refined-shell"><aside class="sidebar"><a href="?concept=refined" class="brand" aria-label="Expadu Today">${brand}</a><nav class="side-nav" aria-label="Main navigation">${navButton('today', 'Today', 'home', true)}${navButton('places', 'Places', 'pin')}${navButton('alerts', 'Alerts', 'bell')}<span class="nav-group">Your city</span>${navButton('departures', 'Departures', 'train')}${navButton('events', 'Events', 'calendar')}${navButton('services', 'Services', 'bag')}<span class="nav-group">Personal</span>${navButton('bureaucracy', 'Bureaucracy', 'document')}${navButton('city', 'More', 'grid')}</nav><div class="side-profile"><button type="button" class="avatar" data-nav="profile" aria-label="Anar’s account">A</button><div><strong>Anar</strong><p>Cologne · Merkenich</p></div></div></aside><main class="main" id="main"><header class="refined-header"><div><p class="date-line">Saturday, 5 September · Cologne</p><h1>Good afternoon, Anar</h1></div><div class="header-actions">${utilityButton('search', 'search', 'Search places and events')}${utilityButton('profile', 'grid', 'Open account and saved items')}</div></header><div class="context-ribbon" aria-label="City context">${contextLinks()}</div>${paperwork()}<section class="composer-panel" aria-labelledby="composer-title"><h2 id="composer-title">What would make a good day?</h2><form id="composer-form"><div class="composer-input-row"><input id="composer-prompt" aria-label="Describe your day" placeholder="A green space and something social…"><button class="button" type="submit">Make a plan ${icon('arrow')}</button></div></form><div class="suggestions"><button type="button" data-prompt="A green space and something social">Plan my weekend</button><button type="button" data-prompt="Time outdoors with the kids">Time with the kids</button><button type="button" data-nav="bureaucracy">Find my paperwork checklist</button></div></section>${discoverSections()}${footer()}</main></div>`;
}

function featureCard(item) {
  const saved = state.saved.has(item.id);
  return `<article class="place-card"><button type="button" class="open-card" data-detail="${item.id}" aria-label="View ${safe(item.title)}"><div class="art"><img src="./assets/${item.image}" alt="${item.id === 'green' ? 'Editorial illustration of a leafy Cologne riverside' : 'Editorial illustration of a welcoming courtyard gathering'}" width="1536" height="1024"><span class="art-caption">Illustration</span></div><div class="card-copy"><p class="card-eyebrow">${item.category} · ${item.location}</p><h3>${concept === 'fresh' ? item.heading : item.title}</h3><p>${concept === 'fresh' ? `${item.title} · ${item.description}` : item.description}</p></div></button><button type="button" class="save-button" data-save="${item.id}" aria-pressed="${saved}" aria-label="${saved ? 'Unsave' : 'Save'} ${safe(item.title)}">${icon('heart')}</button><div class="card-footer"><span class="tag">${icon(item.icon)}${item.id === 'green' ? 'Outdoors' : 'Meet people'}</span>${addButton(item)}</div></article>`;
}
function addButton(item) {
  const selected = state.plan.includes(item.id);
  return `<button type="button" class="add-button" data-add="${item.id}" aria-pressed="${selected}" aria-label="${selected ? 'Remove' : 'Add'} ${safe(item.title)} ${selected ? 'from' : 'to'} your day">${icon(selected ? 'check' : 'plus')}${selected ? 'Added to day' : 'Add to my day'}</button>`;
}
function miniCard(item) {
  const tile = item.icon === 'play' ? 'play' : item.type === 'outdoors' ? 'sport' : 'culture';
  return `<article class="mini-place"><button type="button" class="open-mini" data-detail="${item.id}"><span class="icon-tile ${tile}">${icon(item.icon)}</span><span><h3>${item.title}</h3><p>${item.location} · ${item.category}</p></span></button><button type="button" class="icon-button" data-add="${item.id}" aria-pressed="${state.plan.includes(item.id)}" aria-label="${state.plan.includes(item.id) ? 'Remove' : 'Add'} ${safe(item.title)} ${state.plan.includes(item.id) ? 'from' : 'to'} your day">${icon(state.plan.includes(item.id) ? 'check' : 'plus')}</button></article>`;
}

function renderDiscovery() {
  const matches = (item) => state.filter === 'all' || item.type === state.filter;
  const featured = items.filter((item) => item.image && matches(item));
  const nonFeatured = items.filter((item) => !item.image && matches(item));
  document.querySelector('#featured-places').innerHTML = featured.length ? featured.map(featureCard).join('') : `<p class="no-results">Find the playground and football suggestions below. ${concept === 'fresh' ? 'Add a place to start your day.' : ''}</p>`;
  document.querySelector('#nearby-places').innerHTML = nonFeatured.length ? nonFeatured.slice(0, 3).map(miniCard).join('') : `<p class="no-results">The social event above is the culture example in this prototype.</p>`;
  for (const filter of document.querySelectorAll('[data-filter]')) filter.setAttribute('aria-pressed', String(filter.dataset.filter === state.filter));
  syncAddButtons();
}
function syncAddButtons() {
  for (const button of document.querySelectorAll('[data-add]')) {
    const item = findItem(button.dataset.add);
    if (!item) continue;
    const selected = state.plan.includes(item.id);
    button.setAttribute('aria-pressed', String(selected));
    button.setAttribute('aria-label', `${selected ? 'Remove' : 'Add'} ${item.title} ${selected ? 'from' : 'to'} your day`);
    button.innerHTML = icon(selected ? 'check' : 'plus') + (button.classList.contains('add-button') ? selected ? 'Added to day' : 'Add to my day' : '');
  }
}
function renderDock() {
  const count = state.plan.length;
  document.querySelector('#plan-dock').innerHTML = `<aside class="plan-dock" aria-label="Your day planner"><div class="dock-copy">${icon('route')}<div><strong>${state.daySaved ? 'Your day is saved' : count ? `${count} ${count === 1 ? 'idea' : 'ideas'} for your day` : 'Make a little day of it'}</strong><small>${count ? 'Your choices, all in one place' : 'Pick a place. We’ll take it from there.'}</small></div></div><button type="button" class="button" data-nav="planner">${count ? 'View my day' : 'Build my day'} ${icon('arrow')}</button></aside>`;
}

const dialog = document.querySelector('#detail-dialog');
let activeDialog = '';
let lastDialogTrigger = null;
let toastTimer;
function toast(message) {
  const element = document.querySelector('#toast');
  element.textContent = message;
  element.classList.add('visible');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => element.classList.remove('visible'), 2600);
}
function showDialog(title, contents, kind = '') {
  if (!dialog.open) lastDialogTrigger = document.activeElement;
  activeDialog = kind;
  document.querySelector('#dialog-body').innerHTML = `<header class="modal-head"><h2 id="dialog-title">${safe(title)}</h2><button type="button" class="icon-button" data-action="close-dialog" aria-label="Close dialog">${icon('close')}</button></header><div class="modal-content">${contents}</div>`;
  if (!dialog.open) dialog.showModal();
}
function closeDialog() { dialog.close(); }
dialog.addEventListener('close', () => { activeDialog = ''; if (lastDialogTrigger?.isConnected) lastDialogTrigger.focus(); });
dialog.addEventListener('click', (event) => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) closeDialog(); } });
function showItem(id) {
  const item = findItem(id);
  if (!item) return;
  showDialog(item.title, `${item.image ? `<div class="art"><img src="./assets/${item.image}" alt="Category illustration, not a verified photo of this venue"><span class="art-caption">Illustration</span></div>` : ''}<div class="detail-tags"><span class="tag">${icon('pin')}${item.location}</span><span class="tag">${item.category}</span></div><p>${item.detail}</p>${addButton(item)}<div class="info-row"><span>Opening hours</span><strong>Not verified</strong></div><div class="info-row"><span>Travel time</span><strong>Not calculated</strong></div><div class="info-row"><span>Content</span><strong>Design example</strong></div>`, 'detail');
}
function showPlanner() {
  const selected = state.plan.map(findItem).filter(Boolean);
  showDialog(state.daySaved ? 'Your saved day' : 'A day that feels like you', `<p>${selected.length ? 'These are the places you selected. Remove anything that no longer fits.' : 'Start with a place you like, or explore a sample day.'}</p>${!selected.length ? `<label class="planner-label" for="day-prompt">What are you in the mood for?</label><input class="planner-input" id="day-prompt" placeholder="Some fresh air and a new face"><div class="filter-row"><button type="button" class="filter" data-demo-plan="outdoors">${icon('leaf')}A little outdoors</button><button type="button" class="filter" data-demo-plan="social">${icon('people')}Outdoors + people</button><button type="button" class="filter" data-demo-plan="kids">${icon('play')}With the kids</button></div>` : selected.map((item, index) => `<div class="plan-item"><span class="order-dot">${index + 1}</span><div class="plan-item-copy"><h3>${item.title}</h3><p>${item.location} · ${item.category}</p></div><button type="button" class="icon-button" data-remove="${item.id}" aria-label="Remove ${safe(item.title)} from your day">${icon('close')}</button></div>`).join('')}<div class="planner-actions"><button type="button" class="button secondary" data-action="browse">${icon('plus')}Explore more</button><button type="button" class="button" data-action="save-day"${selected.length ? '' : ' disabled'}>${state.daySaved ? icon('check') + 'Saved for this session' : 'Save this day' + icon('arrow')}</button></div><p class="sample-label" style="margin:20px 0 0">Prototype: selections are kept in this page only. Routes, times and AI planning are not connected.</p>`, 'planner');
}
function toggleItem(id) {
  if (!findItem(id)) return;
  if (state.plan.includes(id)) { state.plan = state.plan.filter((entry) => entry !== id); toast('Removed from your day'); }
  else { state.plan.push(id); toast('Added to your day'); }
  state.daySaved = false;
  syncAddButtons(); renderDock();
  if (activeDialog === 'planner') showPlanner();
}
function showCatalogue(mode = 'places', query = '') {
  let collection = mode === 'events' ? items.filter((item) => item.id === 'night') : mode === 'saved' ? items.filter((item) => state.saved.has(item.id)) : items;
  if (query) collection = collection.filter((item) => `${item.title} ${item.location} ${item.category}`.toLowerCase().includes(query.toLowerCase()));
  const title = mode === 'events' ? 'What’s on in Cologne' : mode === 'saved' ? 'Saved for another day' : 'Find a little of your city';
  showDialog(title, `${mode !== 'saved' ? `<div class="search-field">${icon('search')}<input id="catalogue-search" type="search" aria-label="Search sample places and events" placeholder="A place, neighbourhood, or activity" value="${safe(query)}" data-mode="${mode}"></div>` : ''}<div class="search-results" id="catalogue-results">${collection.length ? collection.map(miniCard).join('') : '<p class="muted">No matching saved places yet.</p>'}</div><p class="sample-label" style="margin-top:20px">Sample records for exploring this design.</p>`, 'catalogue');
}
function showMenu() {
  const links = [['places', 'Places', 'Discover your neighbourhood'], ['events', 'Events', 'Find something to do'], ['departures', 'Departures', 'Plan a journey'], ['bureaucracy', 'Bureaucracy', 'Your paperwork checklist'], ['alerts', 'Alerts', 'Your city updates'], ['services', 'Services', 'Coming soon'], ['planner', 'My day', 'Your selected places']];
  showDialog('Your city, at hand', `<div class="modal-links">${links.map(([nav, label, note]) => `<button type="button" class="modal-link" data-nav="${nav}"><strong>${label}</strong><span>${note}</span>${icon('right')}</button>`).join('')}</div>`, 'menu');
}
function navigate(destination) {
  switch (destination) {
    case 'today': if (dialog.open) closeDialog(); window.scrollTo({ top: 0, behavior: 'smooth' }); break;
    case 'places': case 'events': case 'saved': showCatalogue(destination); break;
    case 'search': showCatalogue('places'); setTimeout(() => document.querySelector('#catalogue-search')?.focus(), 0); break;
    case 'planner': showPlanner(); break;
    case 'city': case 'menu': showMenu(); break;
    case 'profile': showDialog('Your space', `<p>Anar · Cologne</p><div class="modal-links"><button type="button" class="modal-link" data-nav="saved"><strong>Saved places</strong><span>${state.saved.size} saved</span>${icon('right')}</button><button type="button" class="modal-link" data-nav="planner"><strong>My day</strong><span>${state.plan.length} selected</span>${icon('right')}</button></div>`, 'profile'); break;
    case 'location': showDialog('Your neighbourhood', `<p>This design example uses Merkenich, Cologne.</p><div class="info-row"><span>Location</span><strong>Merkenich</strong></div><p class="sample-label" style="margin-top:15px">Your real profile and location are unchanged.</p>`, 'location'); break;
    case 'weather': showDialog('Weather in Cologne', `<div style="display:flex;align-items:center;gap:16px;margin-bottom:15px">${icon('sun')}<strong style="font-size:38px">20°</strong><span>Partly cloudy</span></div><p>Sample weather for comparing the designs. Live weather would be connected in the working app.</p>`, 'weather'); break;
    case 'river': showDialog('Rhine conditions', `<p>The river information stays available when you need it, without occupying a permanent dashboard column.</p><div class="info-row"><span>Current level</span><strong>Not connected</strong></div><div class="info-row"><span>Source update</span><strong>Not connected</strong></div>`, 'river'); break;
    case 'departures': showDialog('Where are you heading?', `<p>Explore how the journey entry would fit into the new design.</p><label class="planner-label" for="journey-destination">Destination</label><input class="planner-input" id="journey-destination" placeholder="A place or station"><button type="button" class="button" data-action="demo-journey">Find a route ${icon('arrow')}</button><p class="sample-label" style="margin-top:20px">Journey results require the working transit service. This prototype does not request routes or your location.</p>`, 'departures'); break;
    case 'bureaucracy': showDialog('Your paperwork checklist', `<p>This is where your saved tasks and their verified next steps would appear.</p><div class="info-row"><span>Task data</span><strong>Not connected in this prototype</strong></div><p class="sample-label" style="margin-top:20px">No legal deadlines, fees or instructions have been invented for the design.</p>`, 'bureaucracy'); break;
    case 'services': showDialog('Services', `<p>Coming soon.</p><p>This destination remains available in both designs. The mockup does not imply the service directory is already working.</p>`, 'services'); break;
    case 'alerts': showDialog('Your updates', `<p>You’re viewing the design prototype. Live notifications are not connected here.</p><div class="modal-links"><button type="button" class="modal-link" data-nav="departures"><strong>Travel updates</strong>${icon('right')}</button><button type="button" class="modal-link" data-nav="bureaucracy"><strong>Paperwork</strong>${icon('right')}</button></div>`, 'alerts'); break;
  }
}

document.querySelector('#app').innerHTML = concept === 'fresh' ? freshPage() : refinedPage();
renderDiscovery(); renderDock();
document.addEventListener('click', (event) => {
  const button = event.target.closest('button');
  if (!button) return;
  if (button.dataset.nav) return navigate(button.dataset.nav);
  if (button.dataset.detail) return showItem(button.dataset.detail);
  if (button.dataset.add) return toggleItem(button.dataset.add);
  if (button.dataset.remove) return toggleItem(button.dataset.remove);
  if (button.dataset.filter) { state.filter = button.dataset.filter; renderDiscovery(); return; }
  if (button.dataset.save) {
    const id = button.dataset.save;
    if (state.saved.has(id)) state.saved.delete(id); else state.saved.add(id);
    renderDiscovery(); toast(state.saved.has(id) ? 'Saved for later' : 'Removed from saved places'); return;
  }
  if (button.dataset.prompt) { document.querySelector('#composer-prompt').value = button.dataset.prompt; document.querySelector('#composer-prompt').focus(); return; }
  if (button.dataset.demoPlan) {
    const choices = { outdoors: ['green', 'table'], social: ['green', 'night'], kids: ['playground', 'family-pitch'] };
    state.plan = [...new Set([...state.plan, ...choices[button.dataset.demoPlan]])]; state.daySaved = false; syncAddButtons(); renderDock(); showPlanner(); return;
  }
  switch (button.dataset.action) {
    case 'close-dialog': closeDialog(); break;
    case 'browse': closeDialog(); document.querySelector('#discovery-title').scrollIntoView({ behavior: 'smooth', block: 'start' }); break;
    case 'save-day': state.daySaved = true; renderDock(); showPlanner(); toast('Your selected day is saved in this demo'); break;
    case 'notify': state.notifications = !state.notifications; button.textContent = state.notifications ? 'Enabled in demo' : 'Enable notifications'; toast('Demo preference only — no device permission requested'); break;
    case 'dismiss-notify': document.querySelector('#notification-strip').remove(); break;
    case 'demo-journey': toast('Live routes are not connected in this prototype'); break;
  }
});
document.addEventListener('input', (event) => {
  if (event.target.id !== 'catalogue-search') return;
  const input = event.target;
  const query = input.value.toLowerCase();
  const collection = items.filter((item) => (input.dataset.mode !== 'events' || item.id === 'night') && `${item.title} ${item.location} ${item.category}`.toLowerCase().includes(query));
  document.querySelector('#catalogue-results').innerHTML = collection.length ? collection.map(miniCard).join('') : '<p class="muted">No matching examples. Try “Seeberg” or “football”.</p>';
});
document.querySelector('#composer-form')?.addEventListener('submit', (event) => { event.preventDefault(); showPlanner(); });
document.querySelector('#about-prototype').addEventListener('click', () => showDialog('Two real, clickable prototypes', `<p><strong>A · Refined familiar</strong> keeps the existing sidebar, serif type and warm identity, but consolidates city context, reduces card repetition and improves the planning entry.</p><p><strong>B · Fresh direction</strong> uses horizontal navigation, discovery as the main activity, city information on demand, and a planning tray built around the places you choose.</p><p>Try filters, place details, saving a place, adding and removing places, opening your day, and saving your selection. Service destinations show their intended entry points.</p><p class="sample-label">This is an isolated local prototype. Content is illustrative; the real app, profile, legal guidance and live services are not changed. Switching designs resets the sample selection.</p>`, 'about'));
