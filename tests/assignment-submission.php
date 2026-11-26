<?php
// No production database, accounts, photos or network requests are used.
require __DIR__.'/assignment-test-db.php';
$path = tempnam(sys_get_temp_dir(), 'quizb-submission-');
DB::$pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = [
    'CREATE TABLE users(id INTEGER PRIMARY KEY, total_points INT DEFAULT 0, quizzes_taken INT DEFAULT 0, exam_reference_photo TEXT, exam_reference_updated_at TEXT)',
    'CREATE TABLE quizzes(id INTEGER PRIMARY KEY, title TEXT, total_questions INT, passing_score INT, require_camera INT, total_attempts INT DEFAULT 0, difficulty TEXT)',
    'CREATE TABLE questions(id INTEGER PRIMARY KEY, quiz_id INT, points INT)',
    'CREATE TABLE options(id INTEGER PRIMARY KEY, question_id INT, is_correct INT)',
    'CREATE TABLE assignments(id INTEGER PRIMARY KEY, quiz_id INT, class_id INT, teacher_id INT, is_active INT, deadline TEXT, require_camera INT DEFAULT 0, require_full_score INT DEFAULT 0)',
    'CREATE TABLE class_members(id INTEGER PRIMARY KEY, class_id INT, user_id INT)',
    'CREATE TABLE assignment_quiz_packages(assignment_id INT, quiz_id INT)',
    'CREATE TABLE attempts(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, player_name TEXT, quiz_id INT, mode TEXT, score INT, total_points INT, correct_count INT, time_taken INT, completed_at TEXT)',
    'CREATE TABLE attempt_answers(attempt_id INT, question_id INT, option_id INT, is_correct INT)',
    "CREATE TABLE assignment_submissions(id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INT, user_id INT, attempt_id INT, submitted_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(assignment_id,user_id))",
    'INSERT INTO users(id) VALUES(7)',
    "INSERT INTO quizzes(id,title,total_questions,passing_score,require_camera,difficulty) VALUES(19,'Test',1,60,0,'medium')",
    'INSERT INTO questions VALUES(1,19,1)', 'INSERT INTO options VALUES(2,1,1)',
    'INSERT INTO assignments(id,quiz_id,class_id,teacher_id,is_active) VALUES(42,19,3,8,1)',
    'INSERT INTO class_members VALUES(1,3,7)',
];
foreach ($schema as $sql) DB::$pdo->exec($sql);
$base = ['quiz_id'=>19,'assignment_id'=>42,'answers'=>['1'=>2],'question_ids'=>[1],'reached_question_ids'=>[1],'time_taken'=>10,'mode'=>'exam','submission_contract'=>2,'submission_id'=>'test-submission-000001'];
$count = 0;
$reference = null;
function check($ok,$name) { if (!$ok) throw new RuntimeException('FAIL '.$name); $GLOBALS['count']++; echo 'PASS '.$name.PHP_EOL; }
function request($body, $handler='attempt_submit', $failure='', $engine=''): array {
    $command = [PHP_BINARY, '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_sqlite', '-d', 'extension=mbstring', __DIR__.'/assignment-request-fixture.php', $GLOBALS['path'], json_encode($body), $handler, $failure, $engine];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $json=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $result=json_decode($json,true);
    if ($exit!==0 || !is_array($result)) throw new RuntimeException('Fixture failed: '.$json.' '.$err);
    return $result;
}
try {
    $first=request($base);
    check($first['success'] && $first['data']['assignment_submitted']===true,'atomic submit confirms assignment');
    $attempt=$first['data']['attempt_id'];
    check(DB::one('SELECT attempt_id FROM assignment_submissions')['attempt_id']===$attempt,'result and assignment are linked');
    $retry=request($base);
    check($retry===$first && DB::one('SELECT COUNT(*) AS n FROM attempts')['n']===1,'lost-response retry returns the same attempt across processes');
    check(DB::one('SELECT total_points, quizzes_taken FROM users')['quizzes_taken']===1,'retry does not increment user statistics twice');
    $changed=$base;$changed['answers']=['1'=>999];
    check(request($changed)['code']===409,'same submission ID cannot overwrite different answers');
    DB::execute('UPDATE assignments SET deadline = ?, require_full_score = 1 WHERE id = 42',['2000-01-01']);
    check(request($base)===$first,'acknowledged retry succeeds after deadline and perfect score');
    check(request(['assignment_id'=>42,'attempt_id'=>$attempt],'assignment_submit')['success'],'legacy retry of same attempt succeeds after deadline');
    $next=$base;$next['submission_id']='test-submission-000002';
    check(request($next)['code']===409 && DB::one('SELECT COUNT(*) AS n FROM attempts')['n']===1,'late new attempt leaves no partial grade');
    DB::execute('UPDATE assignments SET deadline = NULL, require_full_score = 0 WHERE id = 42');
    $failed=request($next,'attempt_submit','UPDATE assignment_submissions');
    check(!$failed['success'] && DB::one('SELECT COUNT(*) AS n FROM attempts')['n']===1,'assignment storage failure rolls back the attempt');
    check(DB::one('SELECT COUNT(*) AS n FROM submission_requests')['n']===1,'failed transaction does not consume submission ID');
    check(DB::one('SELECT quizzes_taken FROM users')['quizzes_taken']===1,'failed transaction rolls back user statistics');
    check(request($next)['success'],'retry after rollback can complete');
    DB::execute('DELETE FROM class_members');
    $unauthorized=$base;$unauthorized['submission_id']='test-submission-000003';
    check(request($unauthorized)['code']===403,'nonmember cannot submit task');
    check(request($unauthorized,'attempt_submit','','nontransactional')['code']===503,'unsafe storage engine cannot claim atomic submission');
    require __DIR__.'/../includes/assignment-submission.php';
    $list=$base;$list['answers']=[['question_id'=>1,'option_id'=>2]];
    check(submissionPayloadHash($base)===submissionPayloadHash($list),'web and Android answer formats share a canonical payload hash');
    DB::execute('DELETE FROM attempts WHERE id = ?', [$attempt]);
    check(request($base)['code']===410,'a teacher reset cannot replay a stale successful result');
    DB::execute('INSERT INTO class_members VALUES(1,3,7)');
    DB::execute('UPDATE quizzes SET require_camera = 1 WHERE id = 19');
    DB::execute('UPDATE assignments SET require_camera = 1 WHERE id = 42');
    $relative = 'uploads/references/assignment-test-'.bin2hex(random_bytes(8)).'.bin';
    $reference = __DIR__.'/../'.$relative;
    if (!is_dir(dirname($reference))) mkdir(dirname($reference),0755,true);
    file_put_contents($reference,str_repeat('synthetic-test-reference;',40));
    DB::execute('UPDATE users SET exam_reference_photo = ? WHERE id = 7', [$relative]);
    $camera=$base;$camera['submission_id']='camera-submission-000001';
    $camera['proctoring_session']=str_repeat('a',64);$camera['_test_reference']=$relative;
    $before=DB::one('SELECT COUNT(*) AS n FROM attempts')['n'];
    check(!request($camera,'attempt_submit','UPDATE assignment_submissions')['success'] && DB::one('SELECT COUNT(*) AS n FROM attempts')['n']===$before,'camera reference read cannot implicitly commit a failed assignment');
    $cameraResult=request($camera);
    check($cameraResult['success'] && DB::one('SELECT attempt_id FROM proctoring_receipts')['attempt_id']===$cameraResult['data']['attempt_id'],'camera receipt and assignment commit together');
    echo $count.' checks passed'.PHP_EOL;
} finally { DB::$pdo=null; @unlink($path); if ($reference) @unlink($reference); }
