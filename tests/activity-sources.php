<?php
// Actual activity endpoints + in-memory SQLite. No production data or schema changes.
class FeedReply extends RuntimeException {public function __construct(public array $data) {parent::__construct('response');}}
function jsonPaginated($rows,$total,$page,$limit) {throw new FeedReply(['data'=>$rows,'meta'=>['total'=>$total,'page'=>$page,'limit'=>$limit]]);}
function jsonSuccess($data) {throw new FeedReply($data);}
function jsonError($message,$status=400) {throw new RuntimeException($message);}
function getPaginationParams() {return [1,10,0];}
function sanitizeString($value) {return (string)$value;}
class DB {
    static PDO $pdo;static int $lookups=0;static ?string $fail=null;static bool $missing=false;
    static function all($sql,$params=[]) {
        if (str_starts_with($sql,'SELECT attempt_id FROM')) {
            self::$lookups++;
            if (self::$fail && str_contains($sql,self::$fail)) {
                if (self::$missing) {$e=new PDOException('test missing table');$e->errorInfo=['42S02',1146,'test'];throw $e;}
                throw new RuntimeException('simulated unavailable metadata');
            }
        }
        $q=self::$pdo->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    static function one($sql,$params=[]) {return self::all($sql,$params)[0]??null;}
}
DB::$pdo=new PDO('sqlite::memory:');DB::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
DB::$pdo->sqliteCreateFunction('IF',fn($condition,$yes,$no)=>$condition?$yes:$no,3);
foreach ([
 'CREATE TABLE users (id INTEGER,name TEXT,is_active INTEGER)',
 'CREATE TABLE categories (id INTEGER,name TEXT,icon TEXT)',
 'CREATE TABLE quizzes (id INTEGER,title TEXT,total_questions INTEGER,passing_score INTEGER,category_id INTEGER,is_published INTEGER)',
 'CREATE TABLE attempts (id INTEGER,user_id INTEGER,quiz_id INTEGER,player_name TEXT,score INTEGER,correct_count INTEGER,time_taken INTEGER,mode TEXT,completed_at TEXT)',
 'CREATE TABLE assignment_attempts (attempt_id INTEGER,assignment_id INTEGER)',
 'CREATE TABLE assignment_submissions (attempt_id INTEGER,assignment_id INTEGER)',
 'CREATE TABLE challenge_participants (attempt_id INTEGER)',
 'CREATE TABLE tournament_participants (attempt_id INTEGER)'
] as $sql) DB::$pdo->exec($sql);
DB::$pdo->exec("INSERT INTO users VALUES (1,'Sample User',1);INSERT INTO categories VALUES(1,'General','book');INSERT INTO quizzes VALUES(1,'Shared quiz',10,60,1,1)");
for ($id=1;$id<=8;$id++) {$q=DB::$pdo->prepare('INSERT INTO attempts VALUES(?,1,1,NULL,80,8,30,?,?)');$q->execute([$id,$id===7?'challenge':($id===8?'instant':'exam'),'2026-11-22 12:00:00']);}
DB::$pdo->exec('INSERT INTO assignment_attempts VALUES(2,100),(4,100),(4,101);INSERT INTO assignment_submissions VALUES(3,200),(4,100);INSERT INTO challenge_participants VALUES(5);INSERT INTO tournament_participants VALUES(6)');
require __DIR__.'/../api/activity.php';
$count=0;
function checkSource($value,$name) {if (!$value) throw new RuntimeException('FAIL '.$name);$GLOBALS['count']++;echo 'PASS '.$name.PHP_EOL;}
function reply($fn) {try {$fn();}catch(FeedReply $r){return $r->data;}throw new RuntimeException('No response');}
$feed=reply(fn()=>activity_feed());$byId=array_column($feed['data'],null,'id');
checkSource(count($feed['data'])===8 && $feed['meta']['total']===8,'source metadata does not duplicate feed rows or totals');
checkSource(DB::$lookups===4,'four bounded batch lookups rather than per-row queries');
checkSource($byId[1]['source_type']==='independent','same quiz played outside assignment stays a general quiz');
checkSource($byId[2]['source_type']==='assignment','assignment attempt history recognized');
checkSource($byId[3]['source_type']==='assignment','legacy submission-only task recognized');
checkSource($byId[4]['source_type']==='assignment','duplicate task links yield one activity');
checkSource($byId[5]['source_type']==='challenge','linked challenge distinguished from search/general quiz');
checkSource($byId[6]['source_type']==='tournament','linked tournament distinguished even when stored mode is exam');
checkSource($byId[7]['source_type']==='challenge','legacy challenge mode recognized');
checkSource($byId[8]['source_type']==='independent','instant mode is not automatically called a class assignment');
checkSource($byId[2]['source_label']==='Tugas Kelas' && $byId[1]['source_label']==='Kuis Umum','labels are clear and do not claim exact historical search navigation');
checkSource(!isset($byId[2]['class_id'],$byId[2]['assignment_title']) && $byId[2]['score']===80,'private class details remain absent and scoring unchanged');
$_GET=['user_id'=>1];$user=reply(fn()=>activity_user_history());checkSource(count($user['data'])===8 && $user['data'][0]['source_type']!=='unknown','user history uses the same classification');
$_GET=['quiz_id'=>1];$quiz=reply(fn()=>activity_quiz_history());checkSource(count($quiz['data'])===8,'quiz history remains compatible');
$_GET=['mode'=>'challenge'];$mode=reply(fn()=>activity_mode_history());checkSource($mode['data'][0]['source_type']==='challenge','mode history remains compatible');
DB::$fail='tournament_participants';$degraded=reply(fn()=>activity_feed());$degraded=array_column($degraded['data'],null,'id');checkSource($degraded[1]['source_type']==='unknown' && $degraded[2]['source_type']==='assignment','lookup outage does not break feed or fabricate search labels');
DB::$missing=true;$missing=reply(fn()=>activity_feed());$missing=array_column($missing['data'],null,'id');checkSource($missing[1]['source_type']==='independent','uninstalled optional MySQL table handled without migration');
$rows=[];$before=DB::$lookups;_activityEnrich($rows);checkSource($rows===[] && DB::$lookups===$before,'empty feed does not issue source queries');
echo 'TOTAL '.$count.' PASS'.PHP_EOL;
