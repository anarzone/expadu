import assert from 'node:assert/strict';
import test from 'node:test';

const model = await import('../../resources/js/components/today/model.ts');
const day = '2026-10-03';
const now = new Date('2026-10-03T10:00:00+02:00');
const slot = (id, start = '14:00', end = '15:00') => ({
    id,
    name: id,
    type: 'personal',
    category: 'appointment',
    swappable: false,
    start_at: `${day}T${start}:00+02:00`,
    end_at: `${day}T${end}:00+02:00`,
    start_time: start,
    end_time: end,
});
const plan = (date, slots) => ({ date, slots, constraints: {} });
const state = (values = {}) => ({
    plans: {},
    draft: null,
    conversations: [],
    ...values,
});
const event = (id, start, end = null) => ({
    id,
    occurrence_start: start,
    occurrence_end: end,
});

test('confirmed workspace state replaces a stale legacy Today summary', () => {
    assert.equal(typeof model.projectDay, 'function');
    const legacy = { date: day, slots: [slot('removed')] };
    assert.equal(model.projectDay(state(), legacy, day).confirmed, null);
});

test('a future saved plan stays future and a draft never becomes confirmed', () => {
    const future = plan('2026-10-04', [slot('future')]);
    const draft = plan(day, [slot('pending')]);
    const view = model.projectDay(
        state({ plans: { '2026-10-04': future }, draft }),
        null,
        day,
    );
    assert.equal(view.confirmed, null);
    assert.equal(view.draft, draft);
    assert.deepEqual(view.upcoming, [future]);
});

test('legacy fallback never labels an undated or different-day plan Today', () => {
    const future = { date: '2026-10-04', slots: [slot('future')] };
    assert.equal(model.projectDay(null, future, day).confirmed, null);
    assert.equal(model.projectDay(null, future, day).upcoming[0], future);
    const undated = { slots: [slot('unknown date')] };
    assert.equal(model.projectDay(null, undated, day).confirmed, null);
    assert.equal(model.projectDay(null, undated, day).undated, undated);
});

test('earlier activities retain order without treating an unknown end as complete', () => {
    const early = slot('earlier', '08:00', '09:00');
    const later = slot('later');
    const unknown = { ...slot('unknown', '09:00'), end_at: undefined };
    const view = model.partitionAgenda([later, unknown, early], now);
    assert.deepEqual(
        view.earlier.map((item) => item.id),
        ['earlier'],
    );
    assert.deepEqual(
        view.current.map((item) => item.id),
        ['unknown', 'later'],
    );
});

test('discovery excludes elapsed, wrong-day, confirmed and pending overlapping events', () => {
    const existing = state({
        plans: { [day]: plan(day, [slot('personal:1')]) },
        draft: plan(day, [slot('event:8', '18:00', '19:00')]),
    });
    const events = [
        event(1, `${day}T09:00:00+02:00`, `${day}T11:00:00+02:00`),
        event(2, '2026-10-04T17:00:00+02:00'),
        event(3, `${day}T14:30:00+02:00`, `${day}T16:00:00+02:00`),
        event(4, `${day}T18:30:00+02:00`, `${day}T20:00:00+02:00`),
        event(8, `${day}T18:00:00+02:00`, `${day}T19:00:00+02:00`),
        event(5, `${day}T15:00:00+02:00`, `${day}T17:00:00+02:00`),
    ];
    assert.deepEqual(
        model
            .availableEvents(events, existing, null, day, now)
            .map((item) => item.id),
        [5],
    );
});

test('unknown event end never invents a duration to fit before another commitment', () => {
    const existing = state({
        plans: { [day]: plan(day, [slot('appointment')]) },
    });
    assert.deepEqual(
        model.availableEvents(
            [event(1, `${day}T12:00:00+02:00`)],
            existing,
            null,
            day,
            now,
        ),
        [],
    );
});

test('route context preserves the activity, full arrival timestamp and real coordinates', () => {
    const item = { ...slot('spot:42'), lat: 50.97, lng: 6.99 };
    assert.deepEqual(model.journeyContext(item, day), {
        to_name: 'spot:42',
        to_lat: 50.97,
        to_lng: 6.99,
        to_id: 'spot:42',
        arrive_by: item.start_at,
        activity: 'spot:42',
        plan_date: day,
        back_label: 'spot:42',
    });
    assert.equal(model.journeyContext({ ...item, lat: undefined }, day), null);
    assert.equal(model.journeyContext(item, '2026-10-04'), null);
});

test('an unavailable saved stop stays in the agenda without a travel action', () => {
    const item = {
        ...slot('spot:42'),
        lat: 50.97,
        lng: 6.99,
        recommendation_status: 'unavailable',
    };

    assert.deepEqual(model.partitionAgenda([item], now).current, [item]);
    assert.equal(model.journeyContext(item, day), null);
});

test('Berlin day remains correct on a phone in another timezone', () => {
    assert.equal(model.dayKey('2026-10-02T23:30:00Z'), day);
});

test('a known public holiday suppresses the conflicting shop-today nudge', () => {
    const sunday = {
        type: 'rhythm_warning',
        key: 'shops_closed_tomorrow',
        title: 'Sunday closure reminder',
    };
    const holiday = {
        type: 'rhythm_warning',
        key: 'holiday_today',
        title: 'Public holiday today',
    };
    const journey = {
        type: 'transit_delay',
        key: 'my-journey',
        title: 'A journey change',
    };
    assert.deepEqual(model.relevantSignals([sunday, holiday, journey]), [
        holiday,
        journey,
    ]);
    assert.deepEqual(model.relevantSignals([sunday, journey]), [
        sunday,
        journey,
    ]);
});
