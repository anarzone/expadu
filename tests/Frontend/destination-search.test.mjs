import assert from 'node:assert/strict';
import { test } from 'node:test';
import { suggestionSearchParams } from '../../resources/js/components/departures/search-request.ts';

test('the selected starting point biases suggestions without changing the typed place', () => {
    const params = suggestionSearchParams('Neumarkt', 'destination', {
        point: { lat: 50.94, lng: 6.95 },
        area: 'Configured district',
    });
    assert.equal(params.get('q'), 'Neumarkt');
    assert.equal(params.get('lat'), '50.94');
    assert.equal(params.get('lng'), '6.95');
    assert.equal(params.has('from_area'), false);
});

test('the configured neighbourhood biases search only when no valid point is available', () => {
    const params = suggestionSearchParams('Central', 'origin', {
        point: { lat: NaN, lng: 6 },
        area: 'Configured district',
    });
    assert.equal(params.get('from_area'), 'Configured district');
    assert.equal(params.has('lat'), false);
    assert.equal(params.has('lng'), false);
    assert.equal(
        suggestionSearchParams('Central', 'origin', {}).has('from_area'),
        false,
    );
});

test('fresh-account searches leave the configured city default to the server without inventing a personal point', () => {
    const params = suggestionSearchParams('Neumarkt', 'origin', {});
    assert.deepEqual(
        [...params],
        [
            ['role', 'origin'],
            ['q', 'Neumarkt'],
        ],
    );
});
