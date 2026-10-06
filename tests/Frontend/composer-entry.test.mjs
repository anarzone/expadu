import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    readComposerEntry,
    eventCandidate,
    mergeRequest,
    reviewTravelDestination,
    withoutEntryContext,
} from '../../resources/js/components/composer/entry-context.ts';

test('an event handoff preserves exact recurrence and date without a submitted message', () => {
    const entry = readComposerEntry(
        '/composer?event=17&start=2026-10-09T18%3A00%3A00%2B02%3A00&date=2026-10-09',
    );
    assert.equal(entry.kind, 'event');
    assert.equal(entry.id, 17);
    assert.equal(entry.date, '2026-10-09');
    assert.equal(entry.submit, undefined);
    assert.equal(eventCandidate(entry, true), 'event:17:1791561600');
});
test('malformed event dates and past invalid calendar days do not become selectable context', () => {
    assert.equal(
        readComposerEntry('/composer?event=17&start=nope&date=2026-02-31'),
        null,
    );
    assert.equal(
        readComposerEntry('/composer?entry=appointment&date=2026-02-31'),
        null,
    );
});
test('a travel context is bounded, has valid coordinates and keeps its original appointment', () => {
    const travel = {
        version: 1,
        name: 'Park',
        lat: 50.93,
        lng: 6.94,
        schedule: 'arrive',
        activityId: 'personal:1',
        planDate: '2026-10-09',
        requestedDate: '2026-10-09',
        requestedTime: '10:00',
    };
    const entry = readComposerEntry(
        '/composer?' + new URLSearchParams({ travel: JSON.stringify(travel) }),
    );
    assert.equal(entry.context.activityId, 'personal:1');
    assert.equal(entry.kind, 'travel');
    assert.equal(
        readComposerEntry(
            '/composer?' +
                new URLSearchParams({
                    travel: JSON.stringify({ ...travel, lat: 999 }),
                }),
        ),
        null,
    );
});
test('incoming requests cannot replace an unfinished message', () => {
    assert.equal(
        mergeRequest('Keep my words', 'Plan my day'),
        'Keep my words\nPlan my day',
    );
    assert.equal(mergeRequest('Plan my day', 'Plan my day'), 'Plan my day');
});
test('appointment starts as an explicit entry and does not imply plan creation', () => {
    assert.deepEqual(
        readComposerEntry('/composer?entry=appointment&date=2026-10-09'),
        { kind: 'appointment', date: '2026-10-09' },
    );
});

test('reviewing a journey retains scheduled departure and the source activity', () => {
    const context = {
        version: 1,
        name: 'Museum',
        lat: 50.94,
        lng: 6.96,
        origin: { name: 'Office', lat: 50.9, lng: 6.9 },
        schedule: 'depart',
        requestedDate: '2026-10-09',
        requestedTime: '15:17',
        destinationId: 'spot:42',
        activityId: 'personal:8',
        planDate: '2026-10-09',
        previousId: 'personal:7',
        previousEnd: '15:00',
        preferredMode: 'transit',
    };
    const dest = reviewTravelDestination(context);
    assert.equal(dest.departAt, '2026-10-09T15:17');
    assert.equal(dest.arriveBy, undefined);
    assert.equal(dest.activityId, 'personal:8');
    assert.equal(dest.planDate, '2026-10-09');
    assert.equal(dest.previousId, 'personal:7');
    assert.equal(dest.fromName, 'Office');
    assert.equal(dest.destinationId, 'spot:42');
    const arrival = reviewTravelDestination({ ...context, schedule: 'arrive' });
    assert.equal(arrival.arriveBy, '2026-10-09T15:17');
    assert.equal(arrival.departAt, undefined);
});
test('dismissed incoming context cannot reappear on reload, while plan navigation remains', () => {
    assert.equal(
        withoutEntryContext(
            '/composer?entry=appointment&date=2026-10-09&plan=2026-10-08#saved',
        ),
        '/composer?plan=2026-10-08#saved',
    );
    assert.equal(
        readComposerEntry(withoutEntryContext('/composer?prompt=Keep+this')),
        null,
    );
});
test('a request is bounded and kept as context for review', () => {
    assert.deepEqual(readComposerEntry('/composer?prompt=Help+me+settle'), {
        kind: 'request',
        text: 'Help me settle',
    });
    assert.equal(
        readComposerEntry('/composer?prompt=' + 'a'.repeat(501)),
        null,
    );
});
