<?php
require __DIR__.'/../includes/legacy-snapshot-cleanup.php';
function checkCleanup(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; }
$root=sys_get_temp_dir().'/quizb-snapshot-cleanup-'.bin2hex(random_bytes(6));
mkdir($root.'/uploads/snapshots',0700,true);mkdir($root.'/uploads/references',0700,true);
$archive=$root.'/uploads/snapshots';
file_put_contents($archive.'/.htaccess','Require all denied');file_put_contents($archive.'/snap_1.jpg','old-image');
file_put_contents($archive.'/snap_noise_1.jpg','old-log-image');file_put_contents($root.'/uploads/references/user.jpg','reference');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE attempt_snapshots (id INTEGER,image_path TEXT);INSERT INTO attempt_snapshots VALUES (1,'uploads/snapshots/snap_1.jpg');
CREATE TABLE assignment_proctoring_logs (snapshot_path TEXT,status TEXT);INSERT INTO assignment_proctoring_logs VALUES ('uploads/snapshots/snap_noise_1.jpg','VERIFIED');
CREATE TABLE attempts (score INTEGER);INSERT INTO attempts VALUES (80);
CREATE TABLE proctoring_receipts (status TEXT);INSERT INTO proctoring_receipts VALUES ('VERIFIED');");
try {
    $report=purgeLegacySnapshots($root,$pdo);
    checkCleanup($report['archive_files']===2 && is_file($archive.'/snap_1.jpg'),'dry-run counts images without deleting');
    file_put_contents($archive.'/unexpected.txt','preserve');
    try {purgeLegacySnapshots($root,$pdo,true);throw new LogicException('Unexpected success');}
    catch (RuntimeException $e) {checkCleanup(is_file($archive.'/snap_1.jpg'),'unexpected entry stops cleanup before any deletion');}
    unlink($archive.'/unexpected.txt');
    $pdo->exec("UPDATE attempt_snapshots SET image_path='uploads/references/user.jpg'");
    try {purgeLegacySnapshots($root,$pdo,true);throw new LogicException('Unexpected success');}
    catch (RuntimeException $e) {checkCleanup(is_file($archive.'/snap_1.jpg'),'metadata outside archive is rejected before deletion');}
    $pdo->exec("UPDATE attempt_snapshots SET image_path='uploads/snapshots/snap_1.jpg'");
    $report=purgeLegacySnapshots($root,$pdo,true);
    checkCleanup($report['archive_files']===2 && !is_file($archive.'/snap_1.jpg') && !is_file($archive.'/snap_noise_1.jpg'),'archive images permanently removed');
    checkCleanup((int)$pdo->query('SELECT COUNT(*) FROM attempt_snapshots')->fetchColumn()===0,'snapshot records deleted');
    checkCleanup($pdo->query('SELECT snapshot_path FROM assignment_proctoring_logs')->fetchColumn()===null,'old log photo link removed');
    checkCleanup(is_file($root.'/uploads/references/user.jpg') && is_file($archive.'/.htaccess') && (int)$pdo->query('SELECT score FROM attempts')->fetchColumn()===80 && $pdo->query('SELECT status FROM proctoring_receipts')->fetchColumn()==='VERIFIED','reference photo, directory protection, grades and verification receipts retained');
    checkCleanup(purgeLegacySnapshots($root,$pdo,true)['archive_files']===0,'cleanup can be rerun safely');
    $empty=new PDO('sqlite::memory:');$empty->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    checkCleanup(purgeLegacySnapshots($root,$empty,true)['attempt_snapshot_rows']===0,'installations without retired tables remain supported');
    // A directory at the archive location is required, never an arbitrary file.
    unlink($archive.'/.htaccess');rmdir($archive);file_put_contents($archive,'not-directory');
    try {purgeLegacySnapshots($root,$pdo,true);throw new LogicException('Unexpected success');}
    catch (RuntimeException $e) {checkCleanup(is_file($archive),'invalid archive target is rejected');}
} finally {
    // Only explicit paths inside this newly-created test fixture; no recursive deletion.
    foreach ([$archive.'/snap_1.jpg',$archive.'/snap_noise_1.jpg',$archive.'/unexpected.txt',$archive.'/.htaccess',$root.'/uploads/references/user.jpg'] as $file) if (is_file($file)) unlink($file);
    if (is_file($archive)) unlink($archive);elseif (is_dir($archive)) rmdir($archive);
    rmdir($root.'/uploads/references');rmdir($root.'/uploads');rmdir($root);
}
