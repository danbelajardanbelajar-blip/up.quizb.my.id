const assert=require('node:assert/strict');
const base=process.env.QUIZB_TEST_HTTP_BASE||'http://127.0.0.1:18089';
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw Error('Use a disposable local Apache server for this test');
(async()=>{
 for(const resource of ['/config/firebase-service-account.json','/bin/notification-worker.php','/tests/notifications-backend.php','/.git/HEAD']){
  const response=await fetch(base+resource,{method:'HEAD'});assert.equal(response.status,403,resource);console.log('PASS: HTTP access denied:',resource);
 }
 assert.equal((await fetch(base+'/assets/css/notifications.css',{method:'HEAD'})).status,200);
 console.log('PASS: public stylesheet remains accessible');
})().catch(error=>{console.error(error);process.exit(1)});
