<?php
// Pure protocol tests. DB is deliberately mocked; no production accounts or grades are changed.
class Reply extends RuntimeException { public function __construct(public int $status) { parent::__construct('test reply'); } }
function jsonError($message,$status=400) { throw new Reply($status); }
class DB {
    static array $writes=[];
    static function one($sql,$params=[]) {
        if (str_contains($sql,'FROM quizzes')) return ['id'=>$params[0],'require_camera'=>1];
        if (str_contains($sql,'FROM assignments')) return ['id'=>$params[0],'class_id'=>3,'quiz_id'=>19,'teacher_id'=>8,'deadline'=>null];
        if (str_contains($sql,'FROM class_members')) return (int)$params[1]===7?['id'=>1]:null;
        return null;
    }
    static function execute($sql,$params=[]) { self::$writes[]=[$sql,$params]; return 1; }
}
$refName='proctoring-test-'.bin2hex(random_bytes(8)).'.jpg';
$refPath=__DIR__.'/../uploads/references/'.$refName;
if (!is_dir(dirname($refPath))) mkdir(dirname($refPath),0755,true);
file_put_contents($refPath,str_repeat('test-reference-only;',40));
function getUserExamReference($id) { return ['has_reference'=>true,'photo_path'=>'uploads/references/'.$GLOBALS['refName']]; }
require __DIR__.'/../includes/proctoring-verification.php';
$count=0;
function check($condition,$name) { if (!$condition) throw new RuntimeException('FAIL '.$name); $GLOBALS['count']++; echo 'PASS '.$name.PHP_EOL; }
function denied($fn,$name) { try {$fn();} catch (Reply $e) {check(in_array($e->status,[403,409]),$name); return;} throw new RuntimeException('FAIL allowed '.$name); }
function fresh($now): array { return ['user_id'=>7,'quiz_id'=>19,'assignment_id'=>42,'reference_version'=>hash_file('sha256',$GLOBALS['refPath']),'started_at'=>$now,'nonce'=>str_repeat('b',64),'stage'=>'tilt','verified_at'=>0,'final_verified_at'=>0,'sample_count'=>0,'frames'=>[]]; }
try {
    $_SESSION=[];$user=['id'=>7,'role'=>'user'];$now=time();
    $s=fresh($now);$old=$s['nonce'];
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'frame-a',$now,false)==='TILT_HEAD','center frame alone cannot complete movement challenge');
    check($old!==$s['nonce'] && $s['verified_at']===0,'nonce rotates without issuing a lease');
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>.2],'frame-b',$now,false)==='CENTER_HEAD','tilt advances to a separate center frame');
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'frame-c',$now,false)==='CENTER_HEAD','same-second challenge replay cannot finish');
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'frame-d',$now+1,false)==='VERIFIED','separate center frame completes movement challenge');
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'frame-e',$now+2,true)==='VERIFIED','final verification issues fresh final lease');
    check($s['sample_count']===2 && $s['final_verified_at']===$now+2,'initial and final samples tracked by server');
    check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'frame-e',$now+3,true)==='FRAME_REPLAY' && $s['final_verified_at']===0,'reused frame revokes final lease');
    foreach (['NO_FACE','RECHECK','REFERENCE_INVALID','UNAVAILABLE','SERVER_BUSY'] as $status) {
        $s=fresh($now);$s['stage']='ready';$s['verified_at']=$now;$s['final_verified_at']=$now;
        check(proctoringAdvance($s,['status'=>$status],'new-'.$status,$now+1,true)===$status && $s['verified_at']===0 && $s['final_verified_at']===0,'failure cannot become verified: '.$status);
    }
    foreach ([NAN,INF,1.1,.49] as $score) {
        $s=fresh($now);check(proctoringAdvance($s,['status'=>'MATCH','cosine'=>$score,'roll'=>0],uniqid(),$now,true)==='INVALID_FEATURE','invalid score rejected');
    }
    $s=fresh($now);$s['stage']='ready';$s['questions_released_at']=$now-45;$s['last_good_at']=$now-45;
    proctoringAdvance($s,['status'=>'MATCH','cosine'=>.9,'roll'=>0],'gap',$now,true);
    check($s['review_required']===true && $s['monitoring_gaps']===1,'monitoring gaps are marked for human review, not silently called fully monitored');
    check(!isset($s['score']) && !isset($s['passed']),'camera state does not decide grades or guilt');
    $scope=['required'=>true,'quiz_id'=>19,'assignment_id'=>42];
    $session=proctoringCreateSession($user,$scope,hash_file('sha256',$refPath));$key=hash('sha256',$session['session']);
    check(proctoringSessionKey($session['session'],$user)===$key,'authenticated session token is usable by its own account');
    denied(fn()=>proctoringSessionKey($session['session'],['id'=>9]),'other user cannot use token');
    denied(fn()=>proctoringSessionKey('bad',$user),'malformed token rejected');
    denied(fn()=>proctoringRequireReceipt($user,['id'=>19],42,['camera_verified'=>true]),'browser boolean cannot authorize submission');
    denied(fn()=>proctoringRequireReceipt($user,['id'=>19],42,['proctoring_session'=>$session['session']]),'unfinished challenge cannot authorize submission');
    $_SESSION['proctoring_v2'][$key]=array_merge(fresh($now),['stage'=>'ready','verified_at'=>$now,'final_verified_at'=>$now,'sample_count'=>2,'questions_released_at'=>$now-10]);
    check(proctoringRequireReceipt($user,['id'=>19],42,['proctoring_session'=>$session['session']])===$key,'fresh scoped server lease allows submission');
    denied(fn()=>proctoringRequireReceipt($user,['id'=>19],43,['proctoring_session'=>$session['session']]),'lease cannot move to another assignment');
    denied(fn()=>proctoringRequireReceipt($user,['id'=>20],42,['proctoring_session'=>$session['session']]),'lease cannot move to another quiz');
    $_SESSION['proctoring_v2'][$key]['final_verified_at']=$now-16;
    denied(fn()=>proctoringRequireReceipt($user,['id'=>19],42,['proctoring_session'=>$session['session']]),'stale final lease rejected');
    $_SESSION['proctoring_v2'][$key]['final_verified_at']=$now;
    file_put_contents($refPath,'changed reference');
    denied(fn()=>proctoringRequireReceipt($user,['id'=>19],42,['proctoring_session'=>$session['session']]),'reset or changed reference invalidates in-flight proof');
    file_put_contents($refPath,str_repeat('test-reference-only;',40));
    proctoringBindReceipt($key,123);
    denied(fn()=>proctoringSessionKey($session['session'],$user),'consumed token cannot produce another attempt');
    check(DB::$writes[count(DB::$writes)-1][1][0]===123,'receipt is bound to the actual attempt id');
    $_SESSION=[];$session=proctoringCreateSession($user,$scope,hash_file('sha256',$refPath));$key=hash('sha256',$session['session']);
    $_SESSION['proctoring_v2'][$key]['questions_released_at']=$now-40;$_SESSION['proctoring_v2'][$key]['last_good_at']=$now-40;$_SESSION['proctoring_v2'][$key]['review_required']=true;
    $replacement=proctoringCreateSession($user,$scope,hash_file('sha256',$refPath));$next=hash('sha256',$replacement['session']);
    check($_SESSION['proctoring_v2'][$next]['questions_released_at']===$now-40 && $_SESSION['proctoring_v2'][$next]['review_required'],'reconnect cannot clear prior monitoring history');
    check(!isset($_SESSION['proctoring_v2'][$key]),'old nonce/session cannot race its replacement');
    check(!proctoringReleaseQuestions($user,19,42,false),'protected questions withheld before verified lease');
    $_SESSION['proctoring_v2'][$next]['stage']='ready';$_SESSION['proctoring_v2'][$next]['verified_at']=$now;
    check(!proctoringReleaseQuestions($user,19,42,true),'preview never exposes protected questions');
    check(proctoringReleaseQuestions($user,19,42,false),'verified cookie-bound lease releases protected questions');
    $_SESSION['proctoring_v2'][$next]['verified_at']=$now-21;
    check(!proctoringReleaseQuestions($user,19,42,false),'expired lease cannot release questions');
    $s=fresh($now);$s['engine_protocol']='pose-burst-v2';$s['started_at']=$now-10;
    $vps=['status'=>'MATCH','cosine'=>.65,'roll'=>0,'engine'=>'opencv-sface-v1','motion_passed'=>false];
    check(proctoringAdvance($s,$vps,'vps-no-motion',$now,false)==='MOTION_REQUIRED','VPS single-frame identity cannot bypass initial motion');
    $vps['motion_passed']=true;
    check(proctoringAdvance($s,$vps,'vps-motion',$now,false)==='VERIFIED','VPS attested motion completes initial challenge');
    $vps['motion_passed']=false;
    check(proctoringAdvance($s,$vps,'vps-periodic',$now+2,true)==='VERIFIED','VPS periodic image can renew a previously verified session');
    $vps['engine']='untrusted';
    check(proctoringAdvance($s,$vps,'vps-bad-engine',$now+4,true)==='INVALID_FEATURE','VPS engine attestation checked on periodic verification');
    echo 'TOTAL '.$count.' PASS'.PHP_EOL;
} finally { unlink($refPath); }
