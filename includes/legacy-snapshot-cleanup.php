<?php
/** One-time, CLI-operated removal of the retired snapshot archive. Never follows stored paths. */
function purgeLegacySnapshots(string $projectRoot, PDO $pdo, bool $execute = false): array {
    $root = realpath($projectRoot);
    if (!$root) throw new RuntimeException('Project root does not exist.');
    $normalize = static fn(string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    $archive = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'snapshots';
    $files = [];
    if (file_exists($archive) || is_link($archive)) {
        // Reject symlinks/junctions in either uploads or snapshots before deleting anything.
        if (!is_dir($archive) || is_link($archive) || is_link(dirname($archive)) ||
            $normalize((string)realpath($archive)) !== $normalize($archive)) {
            throw new RuntimeException('Snapshot archive must be a real directory inside this project.');
        }
        foreach (scandir($archive) as $name) {
            if (in_array($name, ['.', '..', '.htaccess', 'index.html'], true)) continue;
            $file = $archive . DIRECTORY_SEPARATOR . $name;
            if (is_link($file) || !is_file($file) ||
                $normalize((string)realpath($file)) !== $normalize($file) ||
                !preg_match('/\.(?:jpe?g|png|webp|gif)$/i', $name)) {
                throw new RuntimeException('Unexpected archive entry; cleanup stopped before deletion.');
            }
            if (!is_writable($archive)) throw new RuntimeException('Snapshot directory is not writable.');
            $files[] = $file;
        }
    }
    $missing = static function(PDOException $error) use ($pdo): bool {
        return in_array((string)$error->getCode(), ['42S02', '42S22'], true) ||
            ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' &&
             (str_contains($error->getMessage(), 'no such table:') || str_contains($error->getMessage(), 'no such column:')));
    };
    $attemptRows = null; $logRows = null;
    try {
        $attemptRows = (int)$pdo->query('SELECT COUNT(*) FROM attempt_snapshots')->fetchColumn();
        $paths = $pdo->query('SELECT DISTINCT image_path FROM attempt_snapshots WHERE image_path IS NOT NULL');
        foreach ($paths as $row) {
            if (!preg_match('#^uploads/snapshots/[^/\\\\]+$#', ltrim($row['image_path'], '/'))) {
                throw new RuntimeException('Legacy metadata points outside the snapshot archive; inspect before cleanup.');
            }
        }
    } catch (PDOException $e) { if (!$missing($e)) throw $e; }
    try {
        $logRows = (int)$pdo->query('SELECT COUNT(*) FROM assignment_proctoring_logs WHERE snapshot_path IS NOT NULL')->fetchColumn();
        $paths = $pdo->query('SELECT DISTINCT snapshot_path FROM assignment_proctoring_logs WHERE snapshot_path IS NOT NULL');
        foreach ($paths as $row) {
            if (!preg_match('#^uploads/snapshots/[^/\\\\]+$#', ltrim($row['snapshot_path'], '/'))) {
                throw new RuntimeException('Legacy log points outside the snapshot archive; inspect before cleanup.');
            }
        }
    } catch (PDOException $e) { if (!$missing($e)) throw $e; }
    $report = ['mode'=>$execute ? 'execute' : 'dry-run', 'archive_files'=>count($files),
        'attempt_snapshot_rows'=>$attemptRows ?? 0, 'log_photo_links'=>$logRows ?? 0];
    if (!$execute) return $report;
    // DB and files cannot share an atomic transaction. A failure stops with a nonzero exit;
    // rerunning is safe, and metadata is only cleared after all file deletions succeed.
    $pdo->beginTransaction();
    try {
        foreach ($files as $file) {
            // Revalidate immediately before unlink, including the archive parent.
            if ($normalize((string)realpath($archive)) !== $normalize($archive) || is_link($file) ||
                $normalize((string)realpath($file)) !== $normalize($file) || !unlink($file)) {
                throw new RuntimeException('Could not remove archive file; cleanup incomplete, rerun after inspection.');
            }
        }
        if ($attemptRows !== null) $pdo->exec('DELETE FROM attempt_snapshots');
        if ($logRows !== null) $pdo->exec('UPDATE assignment_proctoring_logs SET snapshot_path = NULL WHERE snapshot_path IS NOT NULL');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return $report;
}
