'use strict';
const glyphs = {
  arrow:'<path d="M4 12h16m-6-6 6 6-6 6"/>',right:'<path d="m9 5 7 7-7 7"/>',down:'<path d="m7 10 5 5 5-5"/>',close:'<path d="m6 6 12 12M6 18 18 6"/>',check:'<path d="m5 12 4 4L19 6"/>',plus:'<path d="M12 5v14M5 12h14"/>',
  sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
  pin:'<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
  leaf:'<path d="M20 3C8 2 3 7 4 13c1 7 11 8 14 1 1-3 2-7 2-11Z"/><path d="M3 22c3-8 6-11 11-14"/>',
  ball:'<circle cx="12" cy="12" r="9"/><path d="m9 8 6 0 2 5-5 4-5-4 2-5ZM9 8 7 5m8 3 2-3m0 8 4 1m-14-1-4 1m9 3v4"/>',
  people:'<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4m2-16a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v2"/>',
  document:'<path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/>',
  bookmark:'<path d="M6 3h12v18l-6-4-6 4V3Z"/>',train:'<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/>',
  book:'<path d="M12 5C8 2 4 3 2 4v16c3-2 7-2 10 0 3-2 7-2 10 0V4c-3-1-7-2-10 1ZM12 5v15"/>',
};
const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${glyphs[name] || glyphs.pin}</svg>`;
const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
document.querySelectorAll('[data-icon]').forEach((element) => { element.innerHTML = icon(element.dataset.icon); });
const model = ExpaduConcept.createState();
const response = document.querySelector('#response');
const promptField = document.querySelector('#prompt');
const dialog = document.querySelector('#panel');
let activeResult = null;
let requestVersion = 0;
let toastTimer;
let previousFocus = null;
let selectedArea = 'Merkenich';
let selectedDay = 'Today';
const places = {
  green:{id:'green',title:'Grünzug Seeberg',area:'Seeberg',category:'Green space',image:'riverside.png',why:'An outdoor option to consider. A park category alone cannot tell us how quiet it will be.',unknown:'Quietness and opening hours unverified',glyph:'leaf'},
  pitch:{id:'pitch',title:'Bolzplatz',area:'Chorweiler',category:'Pitch',image:null,why:'A pitch example from the existing catalogue. Football access and availability still need checking.',unknown:'Sport, access and availability unverified',glyph:'ball'},
  social:{id:'social',title:'Newcomers’ Night',area:'Cologne',category:'Social event',image:'gathering.png',why:'An example of an organised gathering. The actual event details must be verified before it becomes a recommendation.',unknown:'Sample event · date and venue unverified',glyph:'people'},
};
function button(label, action, extra = '', secondary = false) { return `<button class="button${secondary ? ' secondary' : ''}" data-action="${action}" ${extra}>${label}</button>`; }
function openPanel(title, body) {
  if (!dialog.open) previousFocus = document.activeElement;
  document.querySelector('#panel-body').innerHTML = `<header class="panel-header"><h2 id="panel-title">${escapeHtml(title)}</h2><button class="icon-button" data-action="close-panel" aria-label="Close dialog">${icon('close')}</button></header><div class="panel-content">${body}</div>`;
  if (!dialog.open) dialog.showModal();
}
dialog.addEventListener('close', () => { if (previousFocus?.isConnected) previousFocus.focus(); });
dialog.addEventListener('click', (event) => { if (event.target === dialog) { const rect=dialog.getBoundingClientRect(); if (event.clientX<rect.left || event.clientX>rect.right || event.clientY<rect.top || event.clientY>rect.bottom) dialog.close(); } });
function notify(text) { const el=document.querySelector('#toast');el.textContent=text;el.classList.add('visible');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('visible'),2400); }
function activateResponse() { response.hidden=false;document.body.classList.add('has-response'); }
function resetHome() { requestVersion++;activeResult=null;response.hidden=true;document.body.classList.remove('has-response');promptField.value='';document.querySelector('.compose-button').disabled=false;window.scrollTo({top:0,behavior:'smooth'});promptField.focus(); }
function updateSavedCount() { const count=model.snapshot().saved.length;const el=document.querySelector('#saved-count');el.hidden=!count;el.textContent=String(count); }
function clarification(title, description, choices) {
  activateResponse();
  response.innerHTML=`<div class="clarification"><h2>${title}</h2><p>${description}</p><div class="clarification-options">${choices.map(([label,key])=>`<button data-choice="${key}">${label}${icon('arrow')}</button>`).join('')}</div></div>`;
}
function showUnavailable(reason) {
  const content={
    volleyball:['Let’s keep it volleyball.','There is no verified volleyball match in these example records. A football pitch would not fulfil your request.'],
    constraint:['That limit matters.','This prototype cannot check the time or distance in your request. I won’t present the sample places as matches for it.'],
    exclusion:['Your exclusions stay in place.','These sample records cannot establish a match for your full request. Nothing you ruled out will be silently added back.'],
    indoor:['A quiet indoor place, then.','There is no verified quiet indoor match in these example records. We would need sourced noise, access and opening information.'],
    game:['You’re looking for a game to join.','A pitch listing cannot tell us whether a group is playing or welcoming new people. That needs a verified session or organiser.'],
  }[reason];
  activateResponse();activeResult=null;
  response.innerHTML=`<div class="clarification"><h2>${content[0]}</h2><p>${content[1]}</p>${button('Edit my request','edit-request','',true)}<p class="muted" style="font-size:12px;margin-top:16px;margin-bottom:0">Your original request is still in the input above.</p></div>`;
}
function showResult(kind) {
  activateResponse();activeResult=kind;
  const item=places[kind];
  const selected=model.snapshot().chosen.includes(item.id);
  const titles={green:'A little breathing room.',pitch:'Room for a kickabout.',social:'A little company could be good.'};
  const sub={green:'An outdoor idea to explore. Quietness needs checking before calling it a match.',pitch:'A place to consider if you already have people to play with.',social:'An organised event is the right kind of result. This one is an illustrative example.'};
  const alternatives={green:['Prefer being indoors?','We can narrow the search to an indoor place instead.','Indoor instead','indoor','book'],pitch:['Looking for players too?','Finding a pitch and finding a game to join are different requests.','Find a game','game','people'],social:['Already have company?','A place to meet your friends is a different request from meeting new people.','A place with friends','friends','pin']};
  const alt=alternatives[kind];
  response.innerHTML=`<header class="response-header"><div><h2>${titles[kind]}</h2><p>${sub[kind]}</p><div class="understood"><span>${escapeHtml(selectedDay)}</span><span>${escapeHtml(selectedArea)}</span><span>${item.category}</span><span class="uncertain">Example result</span></div></div><button class="text-button" data-action="edit-request">Edit request ${icon('arrow')}</button></header><div class="response-cards"><article class="response-card main-card">${item.image ? `<div class="response-art"><img src="../assets/${item.image}" alt="Category illustration, not a verified photograph of ${item.title}"><span class="art-label">Illustration</span></div>` : `<div class="response-art" style="display:grid;place-items:center;background:#f3e6bb"><span style="color:#a0813a">${icon('ball')}</span></div>`}<div class="response-copy"><p class="eyebrow">${item.category} · ${item.area}</p><h3>${item.title}</h3><p class="why">${item.why}</p><span class="unknown">${item.unknown}</span><div class="response-actions"><button class="button${selected?' selected':''}" data-toggle="${item.id}" aria-pressed="${selected}">${icon(selected?'check':'plus')}${selected?'Added':'Add to my day'}</button><button class="text-button" data-detail="${item.id}">Details ${icon('right')}</button></div></div></article><article class="response-card side-card"><span class="tile-icon">${icon(alt[4])}</span><h3>${alt[0]}</h3><p>${alt[1]}</p><button class="button secondary" data-choice="${alt[3]}">${alt[2]} ${icon('arrow')}</button></article></div>${selectionBar()}`;
}
function selectionBar() { const count=model.snapshot().chosen.length;return count?`<div class="selection-bar"><strong>${count} ${count===1?'idea':'ideas'} in your day</strong>${button('Review my day '+icon('arrow'),'review')}</div>`:''; }
function renderPlan(saved = false) {
  activateResponse();activeResult=saved?'saved':'plan';
  const state=model.snapshot();const ids=saved?state.saved:state.chosen;
  if (!ids.length) { response.innerHTML=`<div class="clarification"><h2>Your day is still open.</h2><p>Tell me what you feel like, then keep the ideas you want.</p>${button('Find an idea','edit-request')}${!saved&&state.saved.length?button('Save empty day','save','',true):''}</div>`;return; }
  response.innerHTML=`<div class="saved-state"><div class="success-heading"><span class="success-icon">${icon(saved?'check':'bookmark')}</span><div><h2>${saved?'Your day, kept together.':'Make it your kind of day.'}</h2><p>${saved?'Saved in this prototype session.':'Only the ideas you selected are here.'}</p></div></div>${ids.map((id,i)=>{const item=places[id];return `<div class="plan-row"><span class="number">${i+1}</span><div><h3>${item.title}</h3><p>${item.area} · Timing and travel not checked</p></div>${saved?`<button class="icon-button" data-detail="${id}" aria-label="View ${item.title}">${icon('right')}</button>`:`<button class="icon-button" data-remove="${id}" aria-label="Remove ${item.title}">${icon('close')}</button>`}</div>`;}).join('')}<div class="saved-actions">${saved?button('Edit this day','edit-saved','',true):button('Find another idea','edit-request','',true)}${saved?button('Back to Today','today'):button('Save this day '+icon('check'),'save')}</div><p class="muted" style="font-size:12px;margin-top:17px">Places remain ideas until availability, hours and travel are checked.</p></div>`;
}
async function submitRequest(raw) {
  if (!raw.trim()) { promptField.focus();return; }
  promptField.value=raw;const parsed=model.submit(raw);const version=++requestVersion;
  activeResult=null;activateResponse();document.querySelector('.compose-button').disabled=true;
  response.innerHTML='<div class="loading-dots" role="status" aria-label="Preparing the example"><i></i><i></i><i></i></div>';
  await new Promise(resolve=>setTimeout(resolve,350));
  if (version!==requestVersion) return;
  document.querySelector('.compose-button').disabled=false;
  switch(parsed.kind) {
    case 'quiet':clarification('A little peace and quiet.','Would you rather be outside or settle somewhere indoors?',[['Some fresh air','outdoor'],['Somewhere indoors','indoor']]);break;
    case 'football':clarification('A pitch, or people to play with?','That helps me suggest the right kind of thing.', [['I have people—find a pitch','pitch'],['I’d like to join a game','game']]);break;
    case 'social':clarification('What kind of company?','A place with friends and an event to meet new people need different suggestions.',[['Meet new people','organised'],['Somewhere with my friends','friends']]);break;
    case 'unavailable':showUnavailable(parsed.reason);break;
    case 'travel':showServiceResponse('Getting you there.','The next step is a route to the place in your request. Live routing is not connected in this concept.','departures');break;
    case 'paperwork':showServiceResponse('Let’s find your next step.','Your request belongs with your reviewed paperwork checklist. No legal answer is generated in this concept.','paperwork');break;
    default:clarification('What would help most?','A little more direction will help me avoid guessing.',[['A quiet place','outdoor'],['Something active','pitch'],['Some company','organised']]);
  }
}
function showServiceResponse(title,text,panel) { activateResponse();response.innerHTML=`<div class="clarification"><h2>${title}</h2><p>${text}</p><button class="button" data-panel="${panel}">${panel==='paperwork'?'Open my checklist':'View travel options'}${icon('arrow')}</button></div>`; }
function handleChoice(choice) {
  if(choice==='outdoor')showResult('green');else if(choice==='pitch')showResult('pitch');else if(choice==='organised')showResult('social');
  else if(choice==='friends')clarification('What would you like to do together?','Choose a starting point, or add more detail in the input above.',[['Spend time outdoors','outdoor'],['Play football','pitch']]);
  else showUnavailable(choice);
}
function panelLink(label,id,note='') { return `<button class="panel-link" data-panel="${id}"><span>${label}</span>${note?`<small>${note}</small>`:''}${icon('right')}</button>`; }
function showPanel(name) {
  switch(name) {
    case 'services':openPanel('Everyday life in Cologne',panelLink('Places','explore')+panelLink('Events','events')+panelLink('Departures','departures')+panelLink('Bureaucracy','paperwork')+panelLink('Alerts','alerts')+panelLink('Services','coming-soon','Coming soon')+panelLink('Rhine conditions','river'));break;
    case 'explore':openPanel('Explore Cologne',`<p>Start with a place—or tell Composer what you need.</p>${Object.values(places).map(p=>`<button class="panel-link" data-detail="${p.id}"><span>${p.title}</span><small>${p.area}</small>${icon('right')}</button>`).join('')}`);break;
    case 'events':openPanel('Events',`<p>Organised gatherings need a verified date, venue and organiser.</p><button class="panel-link" data-detail="social"><span>Newcomers’ Night</span><small>Sample event</small>${icon('right')}</button>`);break;
    case 'context':openPanel('Where and when?',`<label class="field-label" for="area">Starting neighbourhood</label><select class="field" id="area"><option${selectedArea==='Merkenich'?' selected':''}>Merkenich</option><option${selectedArea==='Ehrenfeld'?' selected':''}>Ehrenfeld</option><option${selectedArea==='Innenstadt'?' selected':''}>Innenstadt</option></select><label class="field-label" for="day">Day</label><select class="field" id="day"><option${selectedDay==='Today'?' selected':''}>Today</option><option${selectedDay==='Tomorrow'?' selected':''}>Tomorrow</option></select>${button('Use this context','set-context')}<p style="font-size:12px;margin:14px 0 0">Applies to this prototype only. Place distances are not calculated.</p>`);break;
    case 'weather':openPanel('Weather in Cologne',`<p>20° · Partly cloudy</p><p>Sample weather for this visual concept. Live conditions are not connected.</p>`);break;
    case 'paperwork':openPanel('Your paperwork checklist',`<p>Your reviewed tasks, their sources and the correct next action belong here.</p><div class="fact"><span>Case data</span><strong>Not connected</strong></div><p style="font-size:12px;margin-top:16px">No fees, deadlines or legal instructions are invented for this mockup.</p>`);break;
    case 'departures':openPanel('Getting around',`<p>Your destination stays connected to the day you are making.</p><div class="fact"><span>Live departures</span><strong>Not connected</strong></div><div class="fact"><span>Travel times</span><strong>Not calculated</strong></div>`);break;
    case 'river':openPanel('Rhine conditions',`<p>River information remains accessible here. A live level needs a current source.</p>`);break;
    case 'alerts':openPanel('Your updates',`<p>Important changes would appear here, with a clear link to the affected task or journey.</p><p>Live alerts are not connected in this concept.</p>`);break;
    case 'coming-soon':openPanel('Services',`<p>Coming soon, as in the current app.</p>`);break;
    case 'account':openPanel('Your space',`<p>Anar · Cologne</p><button class="panel-link" data-action="saved"><span>My saved day</span>${icon('right')}</button><a class="panel-link" href="./report.html"><span>Audit and design decisions</span>${icon('right')}</a>`);break;
  }
}
function showDetail(id) { const item=places[id];if(!item)return;openPanel(item.title,`<p>${item.why}</p><div class="fact"><span>Neighbourhood</span><strong>${item.area}</strong></div><div class="fact"><span>Hours and access</span><strong>Not verified</strong></div><div class="fact"><span>Travel time</span><strong>Not calculated</strong></div><div class="fact"><span>Evidence</span><strong>Sample catalogue record</strong></div><button class="button${model.snapshot().chosen.includes(id)?' selected':''}" data-toggle="${id}" aria-pressed="${model.snapshot().chosen.includes(id)}">${icon(model.snapshot().chosen.includes(id)?'check':'plus')}${model.snapshot().chosen.includes(id)?'Added to my day':'Add to my day'}</button>`); }
document.querySelector('#composer-form').addEventListener('submit',event=>{event.preventDefault();submitRequest(promptField.value);});
promptField.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();submitRequest(promptField.value);}});
document.addEventListener('click',event=>{
  const target=event.target.closest('button');if(!target)return;
  if(target.dataset.example){submitRequest(ExpaduConcept.examples[target.dataset.example]);return;}
  if(target.dataset.choice){handleChoice(target.dataset.choice);return;}
  if(target.dataset.panel){showPanel(target.dataset.panel);return;}
  if(target.dataset.detail){showDetail(target.dataset.detail);return;}
  if(target.dataset.toggle){const id=target.dataset.toggle;model.toggle(id);if(activeResult&&places[activeResult])showResult(activeResult);else if(activeResult==='plan')renderPlan();if(dialog.open)showDetail(id);notify(model.snapshot().chosen.includes(id)?'Added to your day':'Removed from your day');return;}
  if(target.dataset.remove){model.toggle(target.dataset.remove);renderPlan();return;}
  switch(target.dataset.action){
    case 'today':if(dialog.open)dialog.close();resetHome();break;
    case 'edit-request':promptField.focus();promptField.scrollIntoView({behavior:'smooth',block:'center'});break;
    case 'close-panel':dialog.close();break;
    case 'review':renderPlan();break;
    case 'save':model.save();updateSavedCount();renderPlan(true);notify('Your chosen ideas are saved in this session');break;
    case 'saved':if(dialog.open)dialog.close();renderPlan(true);break;
    case 'edit-saved':model.editSaved();renderPlan();break;
    case 'set-context':selectedArea=document.querySelector('#area').value;selectedDay=document.querySelector('#day').value;document.querySelector('#context-text').textContent=`${selectedDay} · ${selectedArea}`;dialog.close();if(activeResult&&places[activeResult])showResult(activeResult);break;
  }
});
