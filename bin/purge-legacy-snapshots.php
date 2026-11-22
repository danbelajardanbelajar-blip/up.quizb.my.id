<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/legacy-snapshot-cleanup.php';
try {
    if (!is_file(__DIR__ . '/../config/db.php')) throw new RuntimeException('Local database configuration is missing.');
    require_once __DIR__ . '/../config/db.php';
    $args = array_slice($argv, 1);
    if (array_diff($args, ['--execute'])) throw new RuntimeException('Usage: php bin/purge-legacy-snapshots.php [--execute]');
    $report = purgeLegacySnapshots(dirname(__DIR__), DB::conn(), in_array('--execute', $args, true));
    echo json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo $report['mode'] === 'execute'
        ? "DONE: old snapshot files and photo links removed; face references, results and verification receipts retained.\n"
        : "DRY RUN: nothing deleted. Use --execute to permanently remove the retired archive.\n";
} catch (Throwable $error) {
    // Do not disclose DB credentials, image paths, or SQL diagnostics.
    fwrite(STDERR, "Cleanup failed: inspect configuration, archive containment and database connectivity. No successful completion recorded.\n");
    exit(1);
}
