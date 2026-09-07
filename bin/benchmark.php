#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs a full build followed by a no-op incremental build against a real Laravel application, and
 * prints the numbers SPEC.md section 1.2 sets as success criteria. Not part of CI (SPEC.md section
 * 12): timings depend on the machine, so this is for a human to run and read, not to assert on.
 *
 * Usage: php bin/benchmark.php /path/to/laravel-app
 */

require __DIR__.'/../vendor/autoload.php';

use Mapin\BuildRunner;
use Mapin\Store\SqliteStore;

$root = $argv[1] ?? null;
if ($root === null || ! is_dir($root)) {
    fwrite(STDERR, "usage: php bin/benchmark.php /path/to/laravel-app\n");
    exit(1);
}
$root = rtrim($root, '/');
$storagePath = sys_get_temp_dir().'/mapin-benchmark-'.bin2hex(random_bytes(6)).'.sqlite';

$runner = new BuildRunner;
$projectPaths = ['app', 'routes', 'config'];
$vendorPaths = $runner->autoDetectVendorPaths($root);

echo "project: {$root}\n";
echo 'vendor paths indexed: '.($vendorPaths === [] ? '(none found)' : implode(', ', $vendorPaths))."\n\n";

$full = $runner->run($root, $storagePath, $projectPaths, $vendorPaths, [], full: true);
echo "== full build ==\n";
printf("%-16s %s\n", 'duration', round($full->durationSeconds, 2).'s');
printf("%-16s %d\n", 'files seen', $full->filesSeen);
printf("%-16s %d\n", 'files changed', $full->filesChanged);
printf("%-16s %d\n", 'nodes', $full->nodes);
printf("%-16s %d\n", 'edges', $full->edges);
printf("%-16s %d\n", 'unresolved', $full->unresolved);
if ($full->warnings !== []) {
    printf("%-16s %d (first: %s)\n", 'warnings', count($full->warnings), $full->warnings[0]);
}

$incremental = $runner->run($root, $storagePath, $projectPaths, $vendorPaths, [], full: false);
echo "\n== incremental build (no changes) ==\n";
printf("%-16s %s\n", 'duration', round($incremental->durationSeconds, 3).'s');
printf("%-16s %d\n", 'files changed', $incremental->filesChanged);

$store = new SqliteStore($storagePath);
$pdo = $store->pdo();

$callsTotal = (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'calls'")->fetchColumn();
$byResolution = $pdo->query("
    SELECT resolution, COUNT(*) AS n FROM edges WHERE type = 'calls' GROUP BY resolution ORDER BY n DESC
")->fetchAll(PDO::FETCH_KEY_PAIR);
$confidentCalls = (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'calls' AND confidence >= 0.8")->fetchColumn();
$unresolvedCount = (int) $pdo->query('SELECT COUNT(*) FROM unresolved')->fetchColumn();

echo "\n== resolution (calls edges only) ==\n";
printf("%-16s %d\n", 'total calls', $callsTotal);
printf("%-16s %d (%.1f%%)\n", 'confidence>=0.8', $confidentCalls, $callsTotal > 0 ? $confidentCalls / $callsTotal * 100 : 0);
printf("%-16s %d\n", 'unresolved rows', $unresolvedCount);
foreach ($byResolution as $resolution => $count) {
    printf("  %-24s %d\n", $resolution ?? '(none)', $count);
}

foreach (['', '-wal', '-shm'] as $suffix) {
    @unlink($storagePath.$suffix);
}
