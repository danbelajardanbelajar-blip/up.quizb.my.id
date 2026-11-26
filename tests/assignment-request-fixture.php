<?php
require __DIR__.'/assignment-test-db.php';
DB::$pdo = new PDO('sqlite:'.$argv[1], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
DB::$pdo->exec('PRAGMA busy_timeout=10000');
DB::$failOn = $argv[4] ?? '';
DB::$transactional = ($argv[5] ?? '') !== 'nontransactional';
$body = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD']='POST'; $_SESSION=[];
if (!empty($body['_test_reference'])) {
    $token = $body['proctoring_session'];
    $_SESSION['proctoring_v2'][hash('sha256',$token)] = [
        'user_id'=>7,'quiz_id'=>19,'assignment_id'=>42,'started_at'=>time()-30,
        'reference_version'=>hash_file('sha256',__DIR__.'/../'.$body['_test_reference']),
        'stage'=>'ready','verified_at'=>time(),'final_verified_at'=>time(),
        'sample_count'=>2,'questions_released_at'=>time()-20,'engine_protocol'=>'vps-single-v1',
    ];
}
function getCurrentUserOrAnon() { return ['id'=>7,'name'=>'Student','role'=>'user','is_anon'=>false]; }
function requireAuth() { return getCurrentUserOrAnon(); }
function getBody() { return $GLOBALS['body']; }
function getJsonBody() { return getBody(); }
function sanitizeString($value) { return trim(strip_tags($value)); }
function startSecureSession() {}
function jsonSuccess($data=null,$message='OK') { echo json_encode(['success'=>true,'data'=>$data]); exit; }
function jsonError($message,$status=400) { echo json_encode(['success'=>false,'message'=>$message,'code'=>$status]); exit; }
// Deliberately disable external notifications in tests.
foreach (['CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_TIMEOUT_MS','CURLOPT_NOSIGNAL','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_RETURNTRANSFER'] as $i=>$name) if (!defined($name)) define($name,$i);
function curl_init($url) { return null; }
function curl_setopt(...$args) { return true; }
function curl_exec(...$args) { return ''; }
function curl_close(...$args) {}
require __DIR__.'/../api/attempt.php';
require __DIR__.'/../api/assignment.php';
($argv[3] ?? 'attempt_submit')();
