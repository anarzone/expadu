<?php

require __DIR__.'/apply-pack.php';

$checks = [
    'numeric-looking names differ' => preparedPlaceRowFingerprint(['name' => '1992']) !== preparedPlaceRowFingerprint(['name' => '01992']),
    'phone punctuation differs' => preparedPlaceRowFingerprint(['phone' => '+49221123']) !== preparedPlaceRowFingerprint(['phone' => '49221123']),
    'numeric representations agree' => preparedPlaceRowFingerprint(['lat' => 51]) === preparedPlaceRowFingerprint(['lat' => 51.0]),
    'object key order is ignored' => preparedPlaceRowFingerprint(['name' => 'x', 'tags' => ['a' => '1', 'b' => '2']]) === preparedPlaceRowFingerprint(['tags' => ['b' => '2', 'a' => '1'], 'name' => 'x']),
    'list order is retained' => preparedPlaceRowFingerprint(['aliases' => ['a', 'b']]) !== preparedPlaceRowFingerprint(['aliases' => ['b', 'a']]),
];
foreach ($checks as $label => $passed) {
    if (! $passed) {
        throw new RuntimeException('Failed: '.$label);
    }
}
echo json_encode(['checks_passed' => count($checks)], JSON_THROW_ON_ERROR).PHP_EOL;
