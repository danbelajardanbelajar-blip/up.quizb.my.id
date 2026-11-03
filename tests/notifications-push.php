<?php
// Uses real queue queries on the same disposable database and an injected transport.
require __DIR__.'/notifications-backend.php';
DB::execute('DELETE FROM notification_push_deliveries');
DB::execute('DELETE FROM notification_push_jobs');
DB::execute('DELETE FROM user_fcm_tokens');
DB::execute('UPDATE users SET fcm_token = NULL');
DB::execute("INSERT INTO user_fcm_tokens(user_id,token,updated_at) VALUES(1,'shared-token','2020-01-01'),(2,'shared-token','2021-01-01')");
DB::execute("UPDATE users SET fcm_token='shared-token' WHERE id IN (1,2)");
expect(pushDeviceTokens(1)===[] && pushDeviceTokens(2)===['shared-token'],'Old duplicate device bindings resolve only to the most recent owner');
$actor=['id'=>2,'name'=>'Test B','role'=>'user'];$_SERVER['REQUEST_METHOD']='POST';$body=['fcm_token'=>'shared-token'];invoke('auth_unregister_fcm_token');
expect(pushDeviceTokens(1)===[]&&pushDeviceTokens(2)===[],'Revoking latest owner cannot reactivate a historical owner');
DB::execute('DELETE FROM user_fcm_tokens');
DB::execute("UPDATE users SET fcm_token='shared-token' WHERE id IN (1,2)");
expect(pushDeviceTokens(1)===[] && pushDeviceTokens(2)===[],'Ambiguous legacy tokens are suppressed for both accounts');
DB::execute('UPDATE users SET fcm_token = NULL');
DB::execute("INSERT INTO user_fcm_tokens(user_id,token) VALUES(1,'device-a'),(1,'device-b')");
enqueuePush(1,'Title','Body',[]);$job=(int)DB::lastId();$calls=[];
$result=processPushQueue(1,5,function($token,$payload)use(&$calls){$calls[]=$token;return $token==='device-a'?['ok'=>true,'code'=>'SENT']:['retry'=>true,'code'=>'UNAVAILABLE','retry_after'=>120];});
expect($result['retry']===1 && count($calls)===2,'Partial delivery schedules retry without blocking a user request');
expect((int)DB::one('SELECT TIMESTAMPDIFF(SECOND,NOW(),available_at) AS delay FROM notification_push_jobs WHERE id=?',[$job])['delay']>=118,'Worker respects Retry-After minimum');
DB::execute('UPDATE notification_push_jobs SET available_at=NOW() WHERE id=?',[$job]);$calls=[];
$result=processPushQueue(1,5,function($token)use(&$calls){$calls[]=$token;return ['ok'=>true,'code'=>'SENT'];});
expect($result['sent']===1 && $calls===['device-b'],'Retry does not resend to already successful device');
expect(DB::one('SELECT payload FROM notification_push_jobs WHERE id=?',[$job])['payload']==='{}','Terminal jobs discard sensitive notification payload');

enqueuePush(1,'Old account message','Private',[]);$actor=['id'=>2,'name'=>'Test B','role'=>'user'];$_SERVER['REQUEST_METHOD']='POST';$body=['fcm_token'=>'device-a'];invoke('auth_update_fcm_token');
DB::execute("DELETE FROM user_fcm_tokens WHERE token='device-b'");$called=0;
$result=processPushQueue(1,5,function()use(&$called){$called++;return ['ok'=>true,'code'=>'SENT'];});
expect($called===0&&$result['skipped']===1,'Queued notification cannot follow device after account transfer');

DB::execute("INSERT INTO user_fcm_tokens(user_id,token) VALUES(1,'invalid-device')");
enqueuePush(1,'Title','Body',[]);
$result=processPushQueue(1,5,fn()=>fcmClassifyResponse(404,['error'=>['details'=>[['@type'=>'type.googleapis.com/google.firebase.fcm.v1.FcmError','errorCode'=>'UNREGISTERED']]]]));
expect(pushDeviceTokens(1)===[]&&pushDeviceTokens(2)===['device-a'],'Unregistered device is removed without affecting another account');
expect(!fcmClassifyResponse(400,['error'=>['status'=>'INVALID_ARGUMENT']])['invalid'],'Invalid payload is not mistaken for an invalid device token');
expect(fcmClassifyResponse(429,[])['retry']&&fcmClassifyResponse(503,[])['retry']&&fcmClassifyResponse(0,[])['retry'],'Rate limits, server and network errors are retryable');

DB::execute("INSERT INTO user_fcm_tokens(user_id,token) VALUES(1,'device-c')");
enqueuePush(1,'Title','Body',[]);$job=(int)DB::lastId();
for($i=0;$i<6;$i++){DB::execute('UPDATE notification_push_jobs SET available_at=NOW() WHERE id=?',[$job]);processPushQueue(1,5,fn()=>['retry'=>true,'code'=>'OAUTH_FAILED']);}
$row=DB::one('SELECT status,attempts FROM notification_push_jobs WHERE id=?',[$job]);
expect($row['status']==='failed'&&(int)$row['attempts']===6,'Permanent outages stop after bounded attempts and remain observable');
enqueuePush(1,'Expired','Body',[]);$job=(int)DB::lastId();DB::execute('UPDATE notification_push_jobs SET created_at=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE id=?',[$job]);
$result=processPushQueue(1,5,fn()=>throw new RuntimeException('Expired job should not send'));
expect($result['skipped']===1,'Old queued notifications expire before delivery');
enqueuePush(1,'Recover','Body',[]);$job=(int)DB::lastId();DB::execute("UPDATE notification_push_jobs SET status='processing',locked_at=DATE_SUB(NOW(),INTERVAL 10 MINUTE) WHERE id=?",[$job]);
$result=processPushQueue(1,5,fn()=>['ok'=>true,'code'=>'SENT']);
expect($result['sent']===1,'Worker recovers a job left by a crashed process');
$lock='quizb_push_'.substr(hash('sha256',realpath(__DIR__.'/../includes')),0,40);
$other=new PDO($dsn,getenv('QUIZB_TEST_USER')?:'root',getenv('QUIZB_TEST_PASSWORD')?:'');$stmt=$other->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lock]);
expect(processPushQueue(1,5,fn()=>[])['busy']===true,'Concurrent worker exits instead of double processing');
$stmt=$other->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lock]);
expect(!str_contains(file_get_contents(__DIR__.'/../includes/helpers.php'),'fcm.googleapis.com/fcm/send'),'Legacy Firebase endpoint and embedded server key removed');
echo "All push queue integration checks passed without contacting Firebase.\n";
