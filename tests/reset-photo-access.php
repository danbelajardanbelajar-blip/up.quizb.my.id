<?php
// Exercise real endpoint/auth/reset helpers with an isolated roster and no production database.
define('APP_INIT',true);
session_start();
class ResetReply extends RuntimeException {public function __construct(public int $status,public array $payload=[]) {parent::__construct('test reply');}}
function jsonError($message,$status=400) {throw new ResetReply($status,['message'=>$message]);}
function jsonSuccess($data,$message='') {throw new ResetReply(200,$data);}
function getJsonBody(): array {return $GLOBALS['body'];}
class DB {
    static array $resets=[];
    static function one($sql,$params=[]) {
        if (str_contains($sql,'is_active FROM users')) return $GLOBALS['actor'];
        if (str_starts_with($sql,'SHOW COLUMNS')) return ['Field'=>'existing'];
        if (str_contains($sql,'FROM class_members')) {
            if (!str_contains($sql,'c.id = ?') || !str_contains($sql,"u.role IN ('user','pelajar')")) throw new RuntimeException('Missing class/student scope');
            return $params===[7,3,8] && $GLOBALS['classActive'] && in_array($GLOBALS['studentRole'],['user','pelajar'],true) ? ['id'=>1] : null;
        }
        if (str_contains($sql,'SELECT exam_reference_photo')) return $params[0]===7 ? ['exam_reference_photo'=>$GLOBALS['photoPath']] : null;
        return null;
    }
    static function execute($sql,$params=[]) {if (str_contains($sql,'UPDATE users SET exam_reference_photo = NULL')) self::$resets[]=$params[0];return 1;}
}
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../api/proctoring.php';
$count=0;$classActive=true;$studentRole='user';$photoPath=null;
function runReset(string $label,string $role,array $input,int $expected,string $method='POST',int $actorId=8): void {
    $GLOBALS['actor']=['id'=>$actorId,'role'=>$role,'name'=>'Test','is_active'=>1];$_SESSION=['user_id'=>$actorId,'user_role'=>$role];$GLOBALS['body']=$input;$_SERVER['REQUEST_METHOD']=$method;
    $before=count(DB::$resets);
    try {proctoring_reset_reference();throw new RuntimeException('No response');}
    catch (ResetReply $reply) {if ($reply->status!==$expected) throw new RuntimeException($label.': unexpected status '.$reply->status);}
    $mutations=count(DB::$resets)-$before;
    if ($mutations!==($expected===200?1:0)) throw new RuntimeException($label.': unexpected mutation');
    $GLOBALS['count']++;echo 'PASS '.$label.PHP_EOL;
}
try {
    runReset('pengajar resets student in own active class','pengajar',['user_id'=>7,'class_id'=>3],200);
    runReset('another teacher cannot reset that student','pengajar',['user_id'=>7,'class_id'=>3],403,'POST',9);
    runReset('forged class id is rejected','pengajar',['user_id'=>7,'class_id'=>99],403);
    runReset('student outside class is rejected','pengajar',['user_id'=>11,'class_id'=>3],403);
    $classActive=false;runReset('inactive class is rejected','pengajar',['user_id'=>7,'class_id'=>3],403);$classActive=true;
    $studentRole='admin';runReset('teacher cannot reset privileged class member','pengajar',['user_id'=>7,'class_id'=>3],403);$studentRole='pelajar';
    runReset('pelajar role is supported','pengajar',['user_id'=>7,'class_id'=>3],200);
    runReset('student cannot reset their own photo','pelajar',['user_id'=>7,'class_id'=>3],403,'POST',7);
    runReset('unknown teacher role is not an authorization alias','teacher',['user_id'=>7,'class_id'=>3],403);
    runReset('class id is required for pengajar','pengajar',['user_id'=>7],400);
    runReset('GET cannot mutate photo reference','pengajar',['user_id'=>7,'class_id'=>3],405,'GET');
    runReset('admin reset from existing users menu remains supported','admin',['user_id'=>7],200);
    runReset('unknown target returns not found','admin',['user_id'=>999],404);
    runReset('invalid target rejected','admin',['user_id'=>0],400);
    $file=__DIR__.'/../uploads/references/reset-fixture-'.bin2hex(random_bytes(8)).'.jpg';file_put_contents($file,'disposable non-biometric fixture');$photoPath='uploads/references/'.basename($file);
    runReset('authorized reset deletes only disposable reference and clears DB pointer','pengajar',['user_id'=>7,'class_id'=>3],200);
    if (is_file($file)) throw new RuntimeException('Reference was not removed');
    echo 'TOTAL '.$count.' PASS'.PHP_EOL;
} finally {if (isset($file) && is_file($file)) unlink($file);session_destroy();}
