<?php
// CLI only: create the additive v2 tables before enabling the new Android build.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/proctoring.php';
require_once __DIR__.'/../includes/assignment-submission.php';
try {
    assignmentSubmissionTables();
    echo "Assignment submission v2 schema is ready (InnoDB).\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Schema preparation failed: ".$error->getMessage()."\n");
    exit(1);
}
