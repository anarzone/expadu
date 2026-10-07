'use strict';
// Demonstration data restored from the user's selected Today Blue reference.
// These figures are not live departures, weather, or legal requirements.
const previewOverview = {
    checklistName: 'Personal preparation',
    // Stop name from the captured timetable; line letters and times remain demo data.
    stopName: 'Rathaus',
    city: 'Cologne',
    checks: [true, true, false, false],
    departures: [
        { line: 'A', destination: 'City centre', minutes: 4 },
        { line: 'B', destination: 'Central station', minutes: 11 },
    ],
    forecast: [
        { time: 'Now', temperature: 21 },
        { time: '17:00', temperature: 20 },
        { time: '20:00', temperature: 18 },
    ],
};
function overviewDepartures() {
    return previewOverview.departures
        .map(
            (d) =>
                `<div class="c-departure"><span class="c-line">${d.line}</span><span>${d.destination}</span><strong>${d.minutes}<small> min</small></strong></div>`,
        )
        .join('');
}
function overviewForecast() {
    return `<div class="c-forecast">${previewOverview.forecast.map((h) => `<div><span>${h.time}</span>${icon('sun')}<strong>${h.temperature}°</strong></div>`).join('')}</div>`;
}
function renderOverview() {
    const forecast = document.querySelector('#today-forecast');
    if (forecast)
        forecast.innerHTML = `<h3>${icon('sun')}Weather</h3><div class="clarity-data">${overviewForecast()}</div><button class="clarity-action" data-action="weather" aria-label="Open the sample forecast">Forecast${icon('arrow')}</button><small>Sample · not live</small>`;
    const weather = document.querySelector('.weather-button');
    weather.innerHTML = `<span class="weather-sun">${icon('sun')}</span><span><strong>21°</strong><small>Partly sunny</small></span>`;
    weather.setAttribute(
        'aria-label',
        'Sample weather, 21 degrees, partly sunny',
    );
}
const catalogueChecklist = showChecklist;
showChecklist = function () {
    openSheet(
        previewOverview.checklistName,
        `<div class="checklist">${['Notes saved', 'Files added', 'Checklist reviewed', 'Follow-up noted'].map((label, i) => `<label><input type="checkbox" data-overview-check="${i}"${previewOverview.checks[i] ? ' checked' : ''}><span>${label}</span></label>`).join('')}</div><p class="sample-note">Sample preparation · not legal requirements</p>`,
    );
};
showJourney = function () {
    openSheet(
        'Your next connections',
        `<p>From ${safe(previewOverview.stopName)}</p><div class="c-departures-sheet">${overviewDepartures()}</div><div class="button-row">${actionButton('Plan a journey', 'journey-prompt')}</div><p class="sample-note">Sample departures · not live</p>`,
    );
};
const actionBeforeOverview = action;
action = function (name) {
    if (name === 'task-details') {
        catalogueChecklist();
        return;
    }
    if (name === 'weather') {
        openSheet(
            'The hours ahead',
            `${overviewForecast()}<p class="sample-note">Sample forecast · not live</p>`,
        );
        return;
    }
    actionBeforeOverview(name);
};
document.addEventListener('change', (event) => {
    const index = event.target.dataset.overviewCheck;
    if (index === undefined) return;
    previewOverview.checks[Number(index)] = event.target.checked;
    renderOverview();
});
renderOverview();
document.querySelector('#day-date').textContent = new Intl.DateTimeFormat(
    'en-GB',
    { weekday: 'short', day: 'numeric', month: 'long' },
).format(new Date());
