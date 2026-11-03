<?php
// Integration test against a disposable local MySQL/MariaDB database only.
// QUIZB_TEST_DSN=mysql:host=127.0.0.1;port=33077;dbname=quizb_test_notifications
$dsn = getenv('QUIZB_TEST_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=quizb_test_[a-z_]+$/', $dsn)) {
    fwrite(STDERR, "Set QUIZB_TEST_DSN to an isolated local quizb_test_* database.\n"); exit(2);
}
class DB {
    public static PDO $pdo;
    static function conn(): PDO { return self::$pdo; }
    static function all($sql, $params = []): array { $s = self::$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    static function one($sql, $params = []): ?array { return self::all($sql, $params)[0] ?? null; }
    static function execute($sql, $params = []): int { $s = self::$pdo->prepare($sql); $s->execute($params); return $s->rowCount(); }
    static function lastId(): string { return self::$pdo->lastInsertId(); }
}
DB::$pdo = new PDO($dsn, getenv('QUIZB_TEST_USER') ?: 'root', getenv('QUIZB_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
class TestReply extends Exception { function __construct(public $data, public int $status=200) { parent::__construct('test response'); } }
$actor = ['id'=>1, 'name'=>'Test A', 'role'=>'user']; $body=[]; $_SESSION=[];
function requireAuth() { return $GLOBALS['actor']; }
function getCurrentUser() { return $GLOBALS['actor']; }
function getBody() { return $GLOBALS['body']; }
function logoutUser() { $_SESSION=[]; }
function getPaginationParams() { $p=max(1,(int)($_GET['page']??1));$l=min(100,max(1,(int)($_GET['limit']??12)));return [$p,$l,($p-1)*$l]; }
function jsonSuccess($data=null,$message='OK') { throw new TestReply($data); }
function jsonPaginated($data,$total,$page,$limit) { throw new TestReply(['data'=>$data,'meta'=>compact('total','page','limit')]); }
function jsonError($message,$status=400) { throw new TestReply($message,$status); }
function invoke($fn): TestReply { try {$fn();} catch(TestReply $r) {return $r;} throw new RuntimeException('No response: '.$fn); }
function expect($condition,$message): void { if(!$condition)throw new RuntimeException($message); echo "PASS: $message\n"; }
require __DIR__.'/../includes/helpers.php';
require __DIR__.'/../api/auth.php';
require __DIR__.'/../api/notification.php';
require __DIR__.'/../api/challenge.php';
require __DIR__.'/../api/message.php';

foreach(['notification_push_deliveries','notification_push_jobs','challenge_dismissals','challenge_participants','challenges','notifications','user_fcm_tokens','message_threads','messages','quizzes','users'] as $table) DB::execute("DROP TABLE IF EXISTS $table");
DB::execute("CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(100), is_active INT DEFAULT 1, fcm_token VARCHAR(500) NULL)");
DB::execute("CREATE TABLE quizzes (id INT PRIMARY KEY, title VARCHAR(100), is_published INT, total_questions INT DEFAULT 10)");
DB::execute("CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,type VARCHAR(50),title VARCHAR(200),body TEXT,link TEXT,is_read INT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
DB::execute("CREATE TABLE challenges (id INT PRIMARY KEY,quiz_id INT,challenger_id INT,status VARCHAR(30) DEFAULT 'pending',expires_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,start_time DATETIME,winner_id INT NULL,message TEXT)");
DB::execute("CREATE TABLE challenge_participants (id INT AUTO_INCREMENT PRIMARY KEY,challenge_id INT,user_id INT,is_host INT DEFAULT 0,status VARCHAR(30) DEFAULT 'pending',progress TEXT,score_final INT NULL,time_taken INT NULL,attempt_id INT NULL)");
DB::execute("CREATE TABLE message_threads (id INT PRIMARY KEY,user1_id INT,user2_id INT)");
DB::execute("CREATE TABLE messages (id INT PRIMARY KEY,thread_id INT,sender_id INT,is_read INT DEFAULT 0)");
DB::execute("INSERT INTO users (id,name) VALUES (1,'Test A'),(2,'Test B'),(3,'Host'),(4,'Unrelated')");
DB::execute("INSERT INTO quizzes VALUES (10,'Private Quiz',0,10),(11,'Public Quiz',1,10)");
for($i=0;$i<80;$i++) DB::execute("INSERT INTO notifications(user_id,type,title) VALUES (1,'system',?)",['Item '.$i]);
DB::execute("INSERT INTO notifications(user_id,type,title) VALUES (2,'system','Other account'),(1,'message','DM excluded')");
$_GET=['page'=>2,'limit'=>100];$r=invoke('notification_list');
expect(count($r->data['data'])===30 && $r->data['data'][0]['title']==='Item 49', 'Pagination clamps limit before offset and sorts tied timestamps by ID');
expect($r->data['meta']['total']===80,'List excludes messages and other account data');
$_SERVER['REQUEST_METHOD']='POST';$body=['id'=>81];invoke('notification_mark_read');
expect((int)DB::one('SELECT is_read FROM notifications WHERE id=81')['is_read']===0,'Cannot mark another account notification read');
$body=['id'=>0];expect(invoke('notification_mark_read')->status===400,'Invalid notification ID cannot accidentally mark all read');
$body=['all'=>true];invoke('notification_mark_read');
expect((int)invoke('notification_counts')->data['notifications']===0,'Mark all synchronizes unread count');
$_SERVER['REQUEST_METHOD']='DELETE';$_GET=['id'=>81];invoke('notification_delete');
expect(DB::one('SELECT id FROM notifications WHERE id=81')!==null,'Cannot delete another account notification');
$_SERVER['REQUEST_METHOD']='POST';$actor['role']='pengajar';expect(invoke('notification_broadcast_update')->status===403,'Global broadcasts are admin-only');$actor['role']='user';
$before=(int)DB::one('SELECT COUNT(*) AS n FROM notifications')['n'];broadcastNewQuestion(10,1);
expect((int)DB::one('SELECT COUNT(*) AS n FROM notifications')['n']===$before,'Private quiz never creates global notifications');
broadcastNewQuestion(11,1);expect((int)DB::one("SELECT COUNT(*) AS n FROM notifications WHERE type='new_question'")['n']===3,'Published quiz notifies eligible active users excluding creator');

$body=['fcm_token'=>'isolated-test-device'];invoke('auth_update_fcm_token');
$actor['id']=2;invoke('auth_update_fcm_token');
expect((int)DB::one('SELECT COUNT(*) AS n FROM user_fcm_tokens')['n']===1 && (int)DB::one('SELECT user_id FROM user_fcm_tokens')['user_id']===2,'Device registration transfers token ownership to one account');
expect(DB::one('SELECT fcm_token FROM users WHERE id=1')['fcm_token']===null,'Legacy token binding on old account is removed');
invoke('auth_logout');expect((int)DB::one('SELECT COUNT(*) AS n FROM user_fcm_tokens')['n']===0,'Logout unregisters this session device');

DB::execute("INSERT INTO challenges(id,quiz_id,challenger_id,expires_at) VALUES(100,11,3,DATE_ADD(NOW(), INTERVAL 1 DAY)),(101,11,3,'2000-01-01')");
foreach([100,101] as $id) DB::execute("INSERT INTO challenge_participants(challenge_id,user_id,is_host,status) VALUES (?,3,1,'accepted'),(?,2,0,'pending'),(?,1,0,'pending')",[$id,$id,$id]);
$actor=['id'=>4,'name'=>'Unrelated','role'=>'user'];$_SERVER['REQUEST_METHOD']='DELETE';$_GET=['id'=>100];
expect(invoke('challenge_delete')->status===403 && DB::one('SELECT id FROM challenges WHERE id=100')!==null,'Unrelated users cannot delete challenges');
$_GET=['id'=>100];expect(invoke('challenge_status')->status===403,'Unrelated users cannot read challenge participants');
$actor=['id'=>2,'name'=>'Test B','role'=>'user'];$_SERVER['REQUEST_METHOD']='POST';$body=['challenge_id'=>101];
expect(invoke('challenge_accept')->status===409 && DB::one('SELECT status FROM challenges WHERE id=101')['status']==='expired','Expired challenge is rejected and status updated');
$_SERVER['REQUEST_METHOD']='DELETE';$_GET=['id'=>100];invoke('challenge_delete');
expect(DB::one('SELECT id FROM challenges WHERE id=100')!==null && DB::one('SELECT user_id FROM challenge_dismissals WHERE challenge_id=100 AND user_id=2')!==null,'Participant deletion hides only own card');
$actor=['id'=>1,'name'=>'Test A','role'=>'user'];$_SERVER['REQUEST_METHOD']='POST';$body=['challenge_id'=>100];invoke('challenge_accept');
expect(DB::one('SELECT status FROM challenges WHERE id=100')['status']==='playing','Last response starts room with accepted players');
$r=invoke('challenge_list');expect(is_array($r->data['incoming'])&&is_array($r->data['received']),'Challenge list queries execute on MariaDB');
$actor=['id'=>3,'name'=>'Host','role'=>'user'];$_SERVER['REQUEST_METHOD']='DELETE';$_GET=['id'=>100];expect(invoke('challenge_delete')->status===409,'Running room cannot be destroyed by host');
DB::execute("UPDATE challenges SET status='completed' WHERE id=100");invoke('challenge_delete');expect(DB::one('SELECT id FROM challenges WHERE id=100')===null,'Host can delete completed room transactionally');
DB::execute('INSERT INTO message_threads VALUES (7,1,2)');$actor=['id'=>1,'role'=>'user'];$_GET=['thread_id'=>7];
expect(invoke('message_thread')->data['other_id']===2,'Message deep link resolves authorized conversation');
$actor['id']=4;expect(invoke('message_thread')->status===404,'Message deep link denies unrelated account');
echo "All notification backend integration checks passed.\n";
