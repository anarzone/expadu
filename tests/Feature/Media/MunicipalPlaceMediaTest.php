<?php

use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
use App\Media\MediaCandidate;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use Illuminate\Support\Facades\Http;

test('municipal Commons provenance cannot publish a place photo despite an open licence', function (string $artist, string $credit) {
    Http::fake(['commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['1' => [
        'title' => 'File:Venue.jpg',
        'imageinfo' => [[
            'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Venue.jpg',
            'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Venue.jpg',
            'mime' => 'image/jpeg', 'width' => 1600, 'height' => 1000,
            'extmetadata' => [
                'Artist' => ['value' => $artist], 'Credit' => ['value' => $credit],
                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
            ],
        ]],
    ]]]])]);
    $resolver = app(CommonsPhotoResolver::class);
    $metadata = $resolver->commonsMetadata(['Venue.jpg'])['Venue.jpg'];
    $spot = Spot::factory()->create();
    $attachment = app(CaptureMediaCandidate::class)->execute($spot, $resolver->candidate('Venue.jpg', $metadata, 'accepted', 'exact_source', ['source_id' => 'reviewed']));

    expect($attachment->mediaAsset->rights_status)->toBe('pending')
        ->and($attachment->mediaAsset->metadata['source_provenance']['credit'])->toBe($credit)
        ->and(app(PublishedMediaSelector::class)->select($spot, 'hero'))->toBeNull();
})->with([
    'municipal artist' => ['Stadt Köln', 'Own work'],
    'municipal archive' => ['Rhenish Picture Archive', 'Own work'],
    'redistributed source' => ['Fritz Zapp', '<a href="https://offenedaten-koeln.de/dataset/photographs">Collection</a>'],
    'archive credit' => ['Photographer', '<a href="https://www.kulturelles-erbe-koeln.de/documents/obj/123">Rheinisches Bildarchiv</a>'],
]);

test('municipal origin cannot preserve an old approved place photo during refresh', function () {
    $spot = Spot::factory()->create();
    $capture = app(CaptureMediaCandidate::class);
    $candidate = new MediaCandidate(
        provider: 'wikimedia-commons', remoteUrl: 'https://upload.wikimedia.org/venue.jpg',
        providerAssetId: 'venue', sourcePageUrl: 'https://commons.wikimedia.org/wiki/File:Venue.jpg',
        rightsStatus: 'approved', healthStatus: 'active', author: 'Independent Photographer', attribution: 'Independent Photographer · CC BY-SA 4.0',
        licenseCode: 'CC BY-SA 4.0', licenseUrl: 'https://creativecommons.org/licenses/by-sa/4.0/',
        shouldValidate: false, matchStatus: 'accepted', matchMethod: 'reviewed', matchEvidence: ['source' => 'exact venue'],
    );
    $attachment = $capture->execute($spot, $candidate);
    expect(app(PublishedMediaSelector::class)->select($spot, 'hero'))->not->toBeNull();
    $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', remoteUrl: $candidate->remoteUrl, providerAssetId: 'venue',
        metadata: ['source_provenance' => ['credit' => 'Rheinisches Bildarchiv']], shouldValidate: false,
    ));
    expect(app(PublishedMediaSelector::class)->select($spot, 'hero'))->toBeNull();
    expect($attachment->mediaAsset->fresh()->rights_status)->toBe('pending')
        ->and(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero'))->toBeNull();
    $attachment->mediaAsset->fresh()->update(['rights_status' => 'approved']);
    expect(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero'))->toBeNull();
});

test('independent photographs of municipal venues remain publishable', function () {
    $spot = Spot::factory()->create(['name' => 'Museum der Stadt Köln']);
    app(CaptureMediaCandidate::class)->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', remoteUrl: 'https://upload.wikimedia.org/venue.jpg',
        sourcePageUrl: 'https://commons.wikimedia.org/wiki/File:Museum_der_Stadt_Köln.jpg',
        rightsStatus: 'approved', healthStatus: 'active', author: 'Independent Photographer',
        attribution: 'Independent Photographer · CC BY-SA 4.0', licenseCode: 'CC BY-SA 4.0', licenseUrl: 'https://creativecommons.org/licenses/by-sa/4.0/',
        metadata: ['source_provenance' => ['credit' => 'Own work'], 'commons_file' => 'Museum der Stadt Köln.jpg'],
        shouldValidate: false, matchStatus: 'accepted', matchMethod: 'reviewed', matchEvidence: ['source' => 'exact venue'],
    ));
    expect(app(PublishedMediaSelector::class)->select($spot, 'hero'))->not->toBeNull();
});
