<?php
// Real event endpoints against SQLite, with obsolete client image payloads deliberately supplied.
class ArchiveReply extends RuntimeException {public function __construct(public array $data,public int $status=200){parent::__construct('reply');}}
function jsonSuccess($data){throw new ArchiveReply($data);}
function jsonError($message,$status=400){throw new ArchiveReply(['message'=>$message],$status);}
function requireAuth(){return ['id'=>7,'role'=>'pengajar'];}
function requireAdmin(){return ['id'=>7,'role'=>'admin'];}
function getPaginationParams(){return [1,10,0];}
function jsonPaginated($rows,$total,$page,$limit){throw new ArchiveReply(['data'=>$rows,'total'=>$total]);}
function getJsonBody(){return $GLOBALS['body'];}
function sanitizeString($value){return (string)$value;}
class DB {
 static PDO $pdo;
 static function all($sql,$params=[]){$q=self::$pdo->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
 static function one($sql,$params=[]){if(str_starts_with($sql,'SHOW COLUMNS'))return ['Field'=>'present'];return self::all($sql,$params)[0]??null;}
 static function execute($sql,$params=[]){if(str_starts_with($sql,'CREATE TABLE'))return; $q=self::$pdo->prepare($sql);$q->execute($params);}
 static function lastId(){return self::$pdo->lastInsertId();}
}
DB::$pdo=new PDO('sqlite::memory:');DB::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);DB::$pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
DB::$pdo->exec("CREATE TABLE quizzes (id INTEGER,require_camera INTEGER);INSERT INTO quizzes VALUES (1,1);
CREATE TABLE questions (id INTEGER,quiz_id INTEGER);
CREATE TABLE assignment_proctoring_logs (id INTEGER PRIMARY KEY,assignment_id INTEGER,attempt_id INTEGER,user_id INTEGER,event_type TEXT,similarity_score REAL,status TEXT,notes TEXT,created_at TEXT);");
require __DIR__.'/../api/proctoring.php';require __DIR__.'/../api/attempt.php';require __DIR__.'/../api/assignment.php';require __DIR__.'/../api/admin.php';
foreach (['attempt_upload_snapshot','assignment_attempt_snapshots','admin_attempt_snapshots','attempt_save_optimized_snapshot'] as $retired) {
 if(function_exists($retired))throw new RuntimeException('Retired handler still active');
 echo 'PASS removed handler '.$retired.PHP_EOL;
}
$body=['quiz_id'=>1,'assignment_id'=>0,'event_type'=>'fullscreen_violation','snapshot'=>'data:image/jpeg;base64,'.base64_encode(str_repeat('x',2000))];
try {proctoring_log_event();}catch(ArchiveReply $reply){if($reply->status!==200)throw $reply;}
$body['current_question_id']=1;
try {proctoring_swap_question();}catch(ArchiveReply $reply){if($reply->status!==404)throw $reply;}
$rows=DB::all('SELECT * FROM assignment_proctoring_logs');
if(count($rows)!==2 || $rows[0]['status']!=='CLIENT_REPORTED' || $rows[1]['event_type']!=='audio_violation')throw new RuntimeException('Metadata logging broken');
echo 'PASS legacy event/noise image payloads ignored; metadata logs work without image columns or archive tables'.PHP_EOL;
foreach (['api/attempt.php','api/assignment.php','api/admin.php'] as $file) {
 $code=file_get_contents(__DIR__.'/../'.$file);
 if(str_contains($code,'attempt_snapshots') || str_contains($code,'uploads/snapshots'))throw new RuntimeException('Active archive dependency: '.$file);
}
echo 'PASS submit/results/reset paths do not depend on the retired archive'.PHP_EOL;

DB::$pdo->exec("CREATE TABLE users (id INTEGER,name TEXT,email TEXT);INSERT INTO users VALUES (7,'Test','test@example.invalid');
CREATE TABLE assignments (id INTEGER,teacher_id INTEGER,class_id INTEGER);INSERT INTO assignments VALUES (1,7,3);
CREATE TABLE class_members (class_id INTEGER,user_id INTEGER);INSERT INTO class_members VALUES (3,7);
CREATE TABLE assignment_submissions (assignment_id INTEGER,user_id INTEGER,attempt_id INTEGER,submitted_at TEXT);INSERT INTO assignment_submissions VALUES (1,7,5,'2026-11-22');
CREATE TABLE assignment_attempts (id INTEGER,assignment_id INTEGER,user_id INTEGER,attempt_id INTEGER,created_at TEXT);INSERT INTO assignment_attempts VALUES (1,1,7,5,'2026-11-22');
CREATE TABLE attempts (id INTEGER,user_id INTEGER,quiz_id INTEGER,score INTEGER,correct_count INTEGER,time_taken INTEGER,total_points INTEGER,completed_at TEXT,mode TEXT);INSERT INTO attempts VALUES (5,7,1,80,8,30,10,'2026-11-22','exam');
CREATE TABLE proctoring_receipts (attempt_id INTEGER,review_required INTEGER);INSERT INTO proctoring_receipts VALUES (5,0);
ALTER TABLE quizzes ADD COLUMN title TEXT;");
foreach (['assignment_results'=>['id'=>1], 'assignment_student_history'=>['assignment_id'=>1,'user_id'=>7], 'admin_user_history'=>['user_id'=>7]] as $endpoint=>$params) {
 $_GET=$params;
 try {$endpoint();throw new LogicException('No response');}
 catch (ArchiveReply $reply) {if($reply->status!==200)throw $reply; $rows=$reply->data['submissions']??$reply->data['attempts']??$reply->data['data'];if(count($rows)!==1 || (int)$rows[0]['score']!==80)throw new RuntimeException('Result query failed: '.$endpoint);}
 echo 'PASS '.$endpoint.' works without archive table, score retained'.PHP_EOL;
}
