<?php

/*
 * Prints the --filter for one CI shard, balanced by measured time (tests/shard-times.json)
 * instead of Pest's --shard, which cuts the sorted file list into equal counts and put the
 * slow Paperwork files together (one shard 10m45s, the others about 4 minutes).
 *
 *   vendor/bin/pest --parallel --filter "$(php tests/shard.php <index> <total>)"
 *
 * The filter names each test class (as Pest's own --shard does: paratest takes one path only),
 * with "::" so a class never also matches a longer name.
 * Longest file first, each to the shard with the least time so far. A file without a
 * measurement counts as the median and is placed the same way, so every test file runs in
 * exactly one shard. Refresh the times with tests/shard-times.php after big test changes.
 */

[$_, $index, $total] = $argv + [null, null, null];
if (! ctype_digit((string) $index) || ! ctype_digit((string) $total) || (int) $index < 1 || (int) $index > (int) $total) {
    fwrite(STDERR, "usage: php tests/shard.php <index> <total>\n");
    exit(1);
}

$root = dirname(__DIR__);
$files = [];
foreach (['tests/Unit', 'tests/Feature'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);

$measured = json_decode((string) file_get_contents("{$root}/tests/shard-times.json"), true)['times'] ?? [];
$known = array_values(array_filter($measured, 'is_numeric'));
sort($known);
$median = $known === [] ? 1.0 : (float) $known[intdiv(count($known), 2)];

$weights = [];
foreach ($files as $file) {
    $weights[$file] = (float) ($measured[$file] ?? $median);
}
// Longest first; ties by name so every shard computes the same split.
uksort($weights, fn (string $a, string $b): int => [$weights[$b], $a] <=> [$weights[$a], $b]);

$load = array_fill(1, (int) $total, 0.0);
$shards = array_fill(1, (int) $total, []);
foreach ($weights as $file => $seconds) {
    $target = array_keys($load, min($load))[0];
    $shards[$target][] = $file;
    $load[$target] += $seconds;
}

// tests/Feature/Places/FooTest.php → Tests\\Feature\\Places\\FooTest:: in the regex.
echo implode('|', array_map(
    fn (string $file): string => addslashes('Tests\\'.str_replace('/', '\\', substr($file, strlen('tests/'), -strlen('.php')))).'::',
    $shards[(int) $index],
)), PHP_EOL;
