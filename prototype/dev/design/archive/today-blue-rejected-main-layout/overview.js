'use strict';
// Independent preview scenarios. Switching them never resets the user's draft.
const dayPreview={mode:'balanced',visitor:'current',started:true,checks:[true,true,false,false]};
const dayModes={
 limited:{title:'A little clarity for your day',order:['paperwork','travel','weather'],places:'Places to discover',events:'Events to explore'},
 balanced:{title:'A little clarity for your day',order:['paperwork','travel','weather'],places:'Places to discover',events:'Events'},
 paperwork:{title:'One thing to pick up first',order:['paperwork','travel','weather'],places:'A breather for afterwards',events:'Leave room for something good'},
 travel:{title:'Before you head out',order:['travel','weather','paperwork'],places:'Ideas for when you get there',events:'Make an evening of it'},
 rain:{title:'A small change of plans?',order:['weather','travel','paperwork'],places:'Before you choose an outdoor plan',events:'Plans worth checking before you go'},
 free:{title:'Good to have in the background',order:['weather','travel','paperwork'],places:'A little time to explore',events:'Follow your curiosity'},
};
Object.assign(shapes,{rain:'<path d="M6 15a4 4 0 0 1 0-8 6 6 0 0 1 11-1 4.5 4.5 0 0 1 1 9M8 18l-1 3m6-3-1 3m6-3-1 3"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>'});
const checkedCount=()=>dayPreview.checks.filter(Boolean).length;
function paperworkGlance(){if(!dayPreview.started)return startGlance();const count=checkedCount(),needsAttention=dayPreview.mode==='paperwork'&&count<4;return `<article class="glance-item paperwork-glance${needsAttention?' priority':''}" data-glance="paperwork"><div class="glance-label"><span>${icon('document')}Your paperwork</span>${needsAttention?'<b>Pick up here</b>':'<small>Your checklist</small>'}</div><h3>${count===4?'Checklist complete':`${count} of 4 steps checked off`}</h3><div class="check-progress" role="progressbar" aria-label="Sample checklist progress" aria-valuemin="0" aria-valuemax="4" aria-valuenow="${count}">${[0,1,2,3].map(i=>`<span class="${dayPreview.checks[i]?'done':''}"></span>`).join('')}</div><button class="glance-link" data-action="paperwork">${count===4?'Review checklist':'Continue checklist'}${icon('arrow')}</button><small class="data-caption">Sample checklist</small></article>`;}
function travelRows(){const delayed=dayPreview.mode==='travel';return `<div class="departure-row"><span class="route-code">A</span><span class="destination">City centre${delayed?'<small class="delay">Running 5 min later</small>':''}</span><strong>${delayed?'9':'4'}<small> min</small></strong></div><div class="departure-row"><span class="route-code bus">B</span><span class="destination">Central station</span><strong>11<small> min</small></strong></div>`;}
function travelGlance(){if(dayPreview.visitor==='first')return setupGlance('travel');const priority=dayPreview.mode==='travel';return `<article class="glance-item travel-glance${priority?' priority':''}" data-glance="travel"><div class="glance-label"><span>${icon('train')}Your connections</span>${priority?'<b>Delay to check</b>':'<small>Nearby stop</small>'}</div>${travelRows()}<button class="glance-link" data-action="departures">See your departures${icon('arrow')}</button><small class="data-caption">Sample · not live</small></article>`;}
function weatherHours(){const rain=dayPreview.mode==='rain';return [['Now',rain?'18°':'21°','sun'],['17:00',rain?'16°':'20°',rain?'rain':'sun'],['20:00',rain?'15°':'18°',rain?'rain':'sun']].map(([time,temp,glyph])=>`<div><span>${time}</span>${icon(glyph)}<strong>${temp}</strong></div>`).join('');}
function weatherGlance(){if(dayPreview.visitor==='first')return setupGlance('weather');const rain=dayPreview.mode==='rain';return `<article class="glance-item weather-glance${rain?' priority':''}" data-glance="weather"><div class="glance-label"><span>${icon(rain?'rain':'sun')}The hours ahead</span>${rain?'<b>Rain later</b>':'<small>Weather</small>'}</div><div class="forecast-hours">${weatherHours()}</div><button class="glance-link" data-action="${rain?'weather-prompt':'weather'}">${rain?'Find an indoor idea':'See the forecast'}${icon('arrow')}</button><small class="data-caption">Sample · not live</small></article>`;}
function renderDayOverview(){const completedPreparation=dayPreview.mode==='paperwork'&&checkedCount()===4;const config=dayPreview.visitor==='first'?dayModes.balanced:completedPreparation?{...dayModes.free,title:'A little more room in your day',reason:'Your sample preparation list is complete. Discovery can move up.'}:dayModes[dayPreview.mode],glance=document.querySelector('#at-a-glance');glance.innerHTML=`<div class="overview-heading"><div><h2 id="glance-title">${config.title}</h2></div><span class="overview-dot" aria-hidden="true"></span></div><div class="glance-content">${config.order.map(kind=>({paperwork:paperworkGlance,travel:travelGlance,weather:weatherGlance})[kind]()).join('')}</div>`;
 const flow=document.querySelector('#day-flow'),discovery=document.querySelector('#discovery-board'),yourDay=document.querySelector('#your-day-overview');
 if(dayPreview.visitor!=='first'&&(dayPreview.mode==='free'||completedPreparation)){flow.append(discovery,glance,yourDay);}else{flow.append(glance,yourDay,discovery);}
 document.querySelector('#places-title').textContent=config.places;document.querySelector('#events-title').textContent=config.events;
 const rain=dayPreview.mode==='rain';document.querySelector('.weather-compact strong').textContent=rain?'18°':'21°';document.querySelector('.weather-compact small').textContent=rain?'Rain later':'Partly sunny';document.querySelector('.weather-compact').setAttribute('aria-label',rain?'Weather, 18 degrees, rain later':'Weather, 21 degrees, partly sunny');document.querySelector('.weather-compact .weather-art').innerHTML=icon(rain?'rain':'sun');
 document.querySelector('#city-note-text').textContent=dayPreview.visitor==='first'?'City updates':dayPreview.mode==='travel'?'Departure delay':rain?'Rain later':'City updates';
 document.querySelector('.today-board').dataset.day=dayPreview.mode;
 renderYourDay();renderComposerContext();renderExperience();
}
function renderYourDay(){
 const ids=state.hasDraft?state.draft:state.saved,el=document.querySelector('#your-day-overview');
 const cleared=state.hasDraft&&!ids.length&&state.saved.length;
 el.hidden=!ids.length&&!cleared;if(el.hidden)return;
 el.innerHTML=`<div class="your-day-mark">${icon('bookmark')}</div><div class="your-day-content"><span>${state.hasDraft?'Unsaved choices':'Saved day'}</span><h3>${cleared?'No ideas in this draft':safe(samples[ids[0]].title)}${ids.length>1?` <small>+ ${ids.length-1} more</small>`:''}</h3></div><button class="day-resume" data-action="day">${state.hasDraft?'Review':'Resume'}${icon('arrow')}</button>`;
}
function renderComposerContext(){const el=document.querySelector('#composer-context');el.hidden=!state.draft.length;el.innerHTML=state.draft.length?`<span class="context-intro">With these ideas</span><div class="context-scroll">${state.draft.map(id=>`<button data-unkeep="${id}" aria-label="Remove ${safe(samples[id].title)} from your choices">${safe(samples[id].title)}${icon('close')}</button>`).join('')}</div>`:'';document.body.classList.toggle('has-idea-context',state.draft.length>0);}
const updateCountBeforeOverview=updateCount;
updateCount=function(){updateCountBeforeOverview();renderYourDay();renderComposerContext();};
function preparePrompt(text){if(!input.value.trim()){input.value=text;input.dispatchEvent(new Event('input'));}if(sheet.open)sheet.close();input.focus();toast('Ready to compose');}
function showChecklist(){dayPreview.started=true;renderDayOverview();openSheet('Your personal checklist',`<p>Sample preparation · not legal requirements</p><div class="checklist-sample">${['Notes saved','Files added','Checklist reviewed','Follow-up noted'].map((label,i)=>`<label><input type="checkbox" data-check-step="${i}"${dayPreview.checks[i]?' checked':''}><span>${label}</span></label>`).join('')}</div><div class="checklist-summary"><strong id="checklist-count">${checkedCount()} of 4 checked off</strong><span>Personal preparation</span></div><p class="legal-boundary">Legal status not assessed</p>`);}
function showDepartures(){openSheet('Your next connections',`<p>Example stop</p><div class="sheet-departures">${travelRows()}</div><div class="sheet-actions">${button('Plan a journey','travel-prompt',true)}</div><p class="legal-boundary">Sample departures · not live</p>`);}
function showForecast(){openSheet('The hours ahead',`<div class="forecast-hours sheet-forecast">${weatherHours()}</div><div class="sheet-actions">${button('Plan around the weather','weather-prompt',true)}</div><p class="legal-boundary">Sample forecast · not live</p>`);}
const actionBeforeOverview=action;
action=function(name){if(name==='location'){showLocationSetup();return;}if(name==='paperwork'){showChecklist();return;}if(name==='departures'){if(dayPreview.visitor==='first'){showLocationSetup();return;}showDepartures();return;}if(name==='weather'){if(dayPreview.visitor==='first'){showLocationSetup();return;}showForecast();return;}if(name==='weather-prompt'){preparePrompt(dayPreview.mode==='rain'?'I’d like an indoor idea for a rainy afternoon.':'I’d like to spend some time outdoors today.');return;}if(name==='travel-prompt'){preparePrompt('How do I get to the city centre?');return;}if(name==='updates'&&dayPreview.visitor==='first'){openSheet('City updates','<p>Choose a city to see local updates.</p>'+button('Choose a city','location',true));return;}if(name==='updates'){openSheet('What might affect your day',dayPreview.mode==='travel'?`<p>Sample departure delay</p>${button('See departures','departures',true)}`:dayPreview.mode==='rain'?`<p>Sample forecast: rain later</p>${button('See forecast','weather',true)}`:`<p>No sample alerts</p>`);return;}actionBeforeOverview(name);};
document.querySelector('#preview-day').addEventListener('change',event=>{dayPreview.mode=event.target.value;renderDayOverview();});
document.addEventListener('change',event=>{if(event.target.matches('[data-check-step]')){dayPreview.checks[Number(event.target.dataset.checkStep)]=event.target.checked;document.querySelector('#checklist-count').textContent=`${checkedCount()} of 4 checked off`;renderDayOverview();}});
document.addEventListener('click',event=>{const target=event.target.closest('[data-unkeep]');if(target&&state.draft.includes(target.dataset.unkeep))keep(target.dataset.unkeep);});

// Visitor scenarios are isolated in memory; changing the day's topic keeps choices.
const visitorSessions=new Map();
const originalSamples=JSON.parse(JSON.stringify(samples));
function snapshotVisitor(){return {request:input.value,draft:[...state.draft],saved:[...state.saved],hasDraft:state.hasDraft,checks:[...dayPreview.checks],started:dayPreview.started};}
function switchVisitor(visitor){
 visitorSessions.set(dayPreview.visitor,snapshotVisitor());
 cancelPendingCompose();undoAction=null;document.querySelector('#toast').classList.remove('show');
 const initial={request:'',draft:[],saved:[],hasDraft:false,checks:[false,false,false,false],started:false};
 const next=visitorSessions.get(visitor)||(visitor==='returning'?{...initial,draft:['walk'],saved:['walk'],checks:[true,true,false,false],started:true}:initial);
 Object.assign(state,{request:next.request,draft:[...next.draft],saved:[...next.saved],hasDraft:next.hasDraft,kind:null,filter:'any'});
 Object.assign(dayPreview,{visitor,checks:[...next.checks],started:next.started});
 input.value=next.request;input.dispatchEvent(new Event('input'));
 updateCount();renderDayOverview();
}
function startGlance(){return `<article class="glance-item paperwork-glance"><div class="glance-label"><span>${icon('document')}Your paperwork</span><small>Get started</small></div><h3>A place for your next steps</h3><button class="glance-link" data-action="paperwork">Start a checklist${icon('arrow')}</button><small class="data-caption">Personal preparation</small></article>`;}
function setupGlance(kind){return `<article class="glance-item ${kind}-glance"><div class="glance-label"><span>${icon(kind==='travel'?'train':'sun')}${kind==='travel'?'Your connections':'The hours ahead'}</span><small>Location needed</small></div><h3>${kind==='travel'?'Find your local stop':'Weather where you are'}</h3><button class="glance-link" data-action="location">Choose a city${icon('arrow')}</button></article>`;}
function showLocationSetup(){openSheet('Your city',`<div class="service-empty-illustration">${icon('pin')}</div><p>Location search isn’t connected in this preview.</p><div class="sheet-actions">${button('Browse sample places','places',true)}</div>`);}
function renderExperience(){
 const first=dayPreview.visitor==='first',limited=dayPreview.mode==='limited';
 document.querySelector('#greeting').innerHTML=first?'Make today yours<span>.</span>':'Good afternoon, Anar<span>.</span>';
 const weather=document.querySelector('.weather-compact');
 if(first){weather.querySelector('strong').textContent='—';weather.querySelector('small').textContent='Choose a city';weather.setAttribute('aria-label','Choose a city for weather');}
 document.querySelector('.avatar').textContent=first?'E':'A';
 for(const id of Object.keys(samples))Object.assign(samples[id],originalSamples[id]);
 if(limited){samples.cafe.title='A courtyard café and community garden tucked away from the busy streets';samples.cafe.image=null;}
 document.querySelectorAll('.place-tile').forEach(tile=>{
  const target=tile.querySelector('.place-image'),id=target.dataset.detail,item=samples[id];
  target.innerHTML=item.image?`<img src="./assets/${item.image}" alt="Generated sample scene"><span class="image-provenance">Illustration</span><span class="category-label">${icon(id==='walk'?'leaf':'coffee')}${id==='walk'?'Outdoors':'Cafés'}</span>`:`<span class="missing-photo">${icon('coffee')}<span>Photo unavailable</span></span>`;
  tile.querySelector('h3').textContent=item.title;
  target.setAttribute('aria-label',`View ${item.title}`);
  tile.querySelector('[data-keep]').setAttribute('aria-label',`Keep ${item.title}`);
 });
 document.querySelectorAll('.event-meta').forEach((el,i)=>el.textContent=limited?'Date unavailable · Check details':`${i?'Open air':'Outdoor gathering'} · Date to confirm`);
 renderYourDay();renderComposerContext();
}
document.querySelector('#preview-visitor').addEventListener('change',event=>switchVisitor(event.target.value));
renderDayOverview();
