<?php

/*
 * Rebuilds tests/shard-times.json from a JUnit report, for tests/shard.php.
 *
 *   vendor/bin/pest --parallel --exclude-group=slow --log-junit /tmp/junit.xml
 *   php tests/shard-times.php /tmp/junit.xml
 *
 * Each parallel worker's first test pays the database setup (about a minute); a test far
 * slower than the rest of its file is counted at the file's median instead.
 */

[$_, $report] = $argv + [null, null];
if (! is_string($report) || ! is_file($report)) {
    fwrite(STDERR, "usage: php tests/shard-times.php <junit.xml>\n");
    exit(1);
}

$cases = [];
foreach ((new SimpleXMLElement((string) file_get_contents($report)))->xpath('//testcase') as $case) {
    $file = preg_replace('#^.*?/(tests/)#', '$1', explode('::', (string) $case['file'])[0]);
    $cases[$file][] = (float) $case['time'];
}

$median = function (array $values): float {
    sort($values);

    return $values === [] ? 0.0 : (float) $values[intdiv(count($values), 2)];
};
$times = [];
foreach ($cases as $file => $seconds) {
    sort($seconds);
    $slowest = array_pop($seconds);
    if ($seconds !== [] && $slowest > 20 && $slowest > 4 * $median($seconds)) {
        $slowest = $median($seconds);
    } elseif ($seconds === [] && $slowest > 40) {
        $slowest = 2.0;
    }
    $times[$file] = round(array_sum($seconds) + $slowest, 2);
}
ksort($times);

file_put_contents(dirname(__DIR__).'/tests/shard-times.json', json_encode(
    ['measured' => date('Y-m-d').'; seconds per test file', 'times' => $times],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
)."\n");
echo count($times), " files\n";
