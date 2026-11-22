// Proctoring v2 regression suite: mocked UI/HTTP states; real model tests live in proctoring-model.py.
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
const engineCode=fs.readFileSync(path.join(root,'assets/js/quiz-engine.v6.js'),'utf8');
const verifierCode=fs.readFileSync(path.join(root,'assets/js/quiz-face-verifier.js'),'utf8');
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function harness({reference=true,refError=false,modelError=false}={}){
 const timers=new Map(),delayed=new Map(),events=[],listeners=new Map();let id=0,frames=0;
 const track={readyState:'live',enabled:true,muted:false,stops:0,addEventListener:(name,fn)=>listeners.set(name,fn),removeEventListener:(name)=>listeners.delete(name),stop(){this.stops++;this.readyState='ended';}};
 const stream={getVideoTracks:()=>[track],getTracks:()=>[track],getAudioTracks:()=>[]};
 const video={videoWidth:640,videoHeight:480,readyState:4,srcObject:stream,play:async()=>{},pause:()=>{},addEventListener:()=>{},removeEventListener:()=>{}};
 const Q={inits:0,frame:{ready:true,label:'Ready'},initCascade:async()=>{Q.inits++;if(modelError)throw Error('Model unavailable');},evaluateFrame:()=>Q.frame,captureSnapshot:()=>`data:image/jpeg;base64,frame-${++frames}`};
 const ctx={console,AbortSignal,window:{QuizFaceVerifier:Q,location:{hash:''}},navigator:{mediaDevices:{getUserMedia:async()=>stream}},document:{documentElement:{requestFullscreen:async()=>{}},getElementById:()=>null,addEventListener:()=>{},removeEventListener:()=>{},createElement:()=>video},formatTime:()=>'',alert:()=>{},confirm:()=>true,
 setInterval:(fn,ms)=>{timers.set(++id,{fn,ms});return id;},clearInterval:key=>timers.delete(key),setTimeout:(fn,ms)=>{delayed.set(++id,{fn,ms});return id;},clearTimeout:key=>delayed.delete(key)};
 let sequence=[];
 const api={get:async(action,params)=>{events.push({action,params});if(action==='proctoring.check_reference'){if(refError)throw Error('Reference API failed');return{protocol:2,has_reference:reference};}if(action==='quiz.questions')return{quiz:{id:19,require_camera:1},questions:[{id:1,options:[]}]};return{};},post:async(action,body)=>{events.push({action,body});if(action==='proctoring.begin')return{session:'a'.repeat(64),nonce:'b'.repeat(64)};if(action==='proctoring.verify')return sequence.shift()||{status:'VERIFIED',verified:true,next_nonce:('c'+frames.toString(16).padStart(63,'0')).slice(0,64),message:'Verified by server'};if(action==='proctoring.save_reference')return{success:true};if(action==='attempt.submit')return{attempt_id:123,score:80};return{success:true};}};
 ctx.api=api;vm.createContext(ctx);vm.runInContext(engineCode,ctx);const q=ctx.QuizEngine();q.quiz={id:19,require_camera:1};q.assignmentId=42;q.phase='ready';q.questions=[{id:1,options:[{id:2}]}];q.answers={};q.timeLeft=100;q.$refs={faceScanVideo:video,proctorPipVideo:video,proctorFreezeVideo:video};q.$nextTick=fn=>fn();q.startBgMusic=()=>{};q.stopBgMusic=()=>{};q.startProctoringAudio=()=>{};q.initFullscreenListeners=()=>{};q.removeFullscreenListeners=()=>{};
 return{q,ctx,api,Q,stream,track,listeners,video,timers,delayed,events,setSequence:values=>{sequence=values;}};
}
const verdict=(status,verified=false,n='d')=>({status,verified,next_nonce:n.repeat(64),message:status});

test('returning participant loads detector and must verify before seeing questions or playing',async()=>{
 const h=harness();h.q.questions=[];h.q._cameraQuestionsPending=true;h.q._questionLoadParams={id:19,assignment_id:42};h.setSequence([verdict('TILT_HEAD'),verdict('CENTER_HEAD'),verdict('VERIFIED',true)]);
 await h.q.startQuiz();await settle();assert.equal(h.Q.inits,1);assert.equal(h.q.phase,'face_scan');assert(!h.events.some(e=>e.action==='proctoring.log_event'));
 for(let i=0;i<2;i++){await h.timers.get(h.q._scanInterval).fn();assert.equal(h.q.phase,'face_scan');assert.equal(h.q.questions.length,0);}
 await h.timers.get(h.q._scanInterval).fn();assert.equal(h.q.phase,'playing');assert.equal(h.q.questions.length,1);assert.equal(h.events.filter(e=>e.action==='quiz.questions').length,1);assert(!h.events.some(e=>e.body?.status==='MATCH'));
});

test('reference or detector failure cannot start an exam',async()=>{
 for(const options of [{refError:true},{modelError:true}]){const h=harness(options);await h.q.startQuiz();assert.equal(h.q.phase,'camera_error');assert.equal(h.q.startTimeMs,undefined);assert(!h.events.some(e=>e.action==='attempt.submit'));}
});

test('new reference requires two seconds of actual stable time rather than callback count',async()=>{
 const h=harness({reference:false});let clock=1000;h.ctx.Date={now:()=>clock};await h.q.startQuiz();await settle();const tick=h.timers.get(h.q._scanInterval).fn;
 for(let i=0;i<30;i++)await tick();assert.equal(h.events.filter(e=>e.action==='proctoring.save_reference').length,0);
 clock+=2100;await tick();assert.equal(h.events.filter(e=>e.action==='proctoring.save_reference').length,1);
});

test('face absence freezes selection and timer; local presence alone cannot unlock it',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.q.startTimer();h.Q.frame={ready:false,label:'NO_FACE'};
 await h.q.takeAndUploadSnapshot();assert.equal(h.q.showFaceWarning,true);h.q.selectOption(1,2);assert.equal(h.q.answers[1],undefined);h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,100);
 h.Q.frame={ready:true};h.setSequence([verdict('RECHECK'),verdict('VERIFIED',true)]);await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.q.showFaceWarning,true);await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.q.showFaceWarning,false);h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,99);
});

test('invalid or truthy string verdict never unlocks the exam',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.setSequence([verdict('VERIFIED','true')]);await h.q.takeAndUploadSnapshot();assert.equal(h.q.showFaceWarning,true);assert.equal(h.q._lastServerVerified,0);
});

test('camera ended event stops the stream and preserves answers/time for recovery',async()=>{
 const h=harness();await h.q.startQuiz();h.q.phase='playing';h.q.answers={1:2};h.q.timeLeft=77;h.listeners.get('ended')();assert.equal(h.q.phase,'camera_error');assert.equal(h.q.answers[1],2);assert.equal(h.q.timeLeft,77);assert.equal(h.q.cameraStream,null);assert.equal(h.listeners.size,0);assert(h.track.stops>0);
});

test('unready or stopped camera never yields fabricated image data',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.track.readyState='ended';await assert.rejects(h.q.captureCurrentSnapshot(),/Kamera/);assert(!h.events.some(e=>e.action==='proctoring.verify'));
});

test('late camera permission result is stopped after navigation or cancellation',async()=>{
 const h=harness();let release;h.ctx.navigator.mediaDevices.getUserMedia=()=>new Promise(r=>release=r);const pending=h.q.startQuiz();await settle();h.q.stopCamera();release(h.stream);await pending;assert.equal(h.q.cameraStream,null);assert(h.track.stops>0);assert(!h.events.some(e=>e.action==='proctoring.check_reference'));
});

test('late server response cannot revive an invalidated camera generation',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q._proctorSession='a'.repeat(64);h.q._proctorNonce='b'.repeat(64);let release;h.api.post=()=>new Promise(r=>release=r);const request=h.q._verifyCamera(h.video);h.q.stopCamera();release(verdict('VERIFIED',true));const response=await request;assert.equal(response.status,'CANCELLED');assert.equal(h.q._lastServerVerified,0);
});

test('only one verification request can be in flight',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q._proctorSession='a'.repeat(64);h.q._proctorNonce='b'.repeat(64);let release,calls=0;h.api.post=()=>{calls++;return new Promise(r=>release=r);};const first=h.q._verifyCamera(h.video);const other=await h.q._verifyCamera(h.video);assert.equal(other.status,'BUSY');assert.equal(calls,1);release(verdict('VERIFIED',true));await first;
});

test('failed final verification cannot submit answers; failed transport keeps camera and answers',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.q.answers={1:2};h.setSequence([verdict('RECHECK')]);await h.q.submitAnswers();assert(!h.events.some(e=>e.action==='attempt.submit'));assert.equal(h.q.answers[1],2);
 h.q.showFaceWarning=false;h.api.post=async(action,body)=>{h.events.push({action,body});if(action==='proctoring.verify')return verdict('VERIFIED',true);if(action==='attempt.submit')throw Error('network');return{};};await h.q.submitAnswers();assert.equal(h.q.cameraStream,h.stream);assert.equal(h.q.answers[1],2);assert.equal(h.q.loading,false);
});

test('successful submit binds assignment/session and retries assignment linkage without another attempt',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.q.answers={1:2};const original=h.api.post;let linkFails=true;h.api.post=async(action,body)=>{if(action==='assignment.submit'&&linkFails)throw Error('network');return original(action,body);};await h.q.submitAnswers();assert.equal(h.q.phase,'submission_error');const payload=h.events.find(e=>e.action==='attempt.submit').body;assert.equal(payload.assignment_id,42);assert.equal(payload.proctoring_session,'a'.repeat(64));linkFails=false;await h.q.retryAssignmentSubmission();assert.equal(h.events.filter(e=>e.action==='attempt.submit').length,1);assert.equal(h.q.phase,'submitted');
});

test('cascade loading is shared across concurrent callers, failures reject and can be retried',async()=>{
 const cascade=fs.readFileSync(path.join(root,'assets/js/facefinder.bin'));let calls=0,fail=false;
 const context={window:{},AbortSignal,console,fetch:async()=>{calls++;return{ok:!fail,arrayBuffer:async()=>cascade.buffer.slice(cascade.byteOffset,cascade.byteOffset+cascade.byteLength)};}};vm.createContext(context);vm.runInContext(verifierCode,context);const Q=context.window.QuizFaceVerifier;
 const [a,b]=await Promise.all([Q.initCascade(),Q.initCascade()]);assert.equal(a,b);assert.equal(calls,1);Q.cascadeClassifier=null;fail=true;await assert.rejects(Q.initCascade());fail=false;await Q.initCascade();assert.equal(calls,3);
 assert.equal(Q.compareWithReference({}).match,false);assert.equal(Q.compareDescriptors({lbpHist:[]},{lbpHist:[]}).match,false);
});

test('real HTTP utility unwraps camera responses, sends CSRF and rejects stale account responses',async()=>{
 const calls=[];const c={console,URLSearchParams,fetch:async(url,options)=>{calls.push({url,options});return new Response(JSON.stringify({success:true,data:{protocol:2,has_reference:true}}),{headers:{'X-CSRF-Token':'test-csrf'}});}};vm.createContext(c);vm.runInContext(fs.readFileSync(path.join(root,'assets/js/utils.v5.js'),'utf8')+'\nglobalThis.testApi=api;',c);c.testApi.setToken('test-csrf');const ref=await c.testApi.get('proctoring.check_reference');assert.equal(ref.protocol,2);assert.equal(ref.has_reference,true);await c.testApi.post('proctoring.verify',{session:'test'});assert.equal(calls[1].options.headers['X-CSRF-Token'],'test-csrf');let release;c.fetch=()=>new Promise(resolve=>release=resolve);const stale=c.testApi.get('proctoring.check_reference');c.testApi.invalidateSession();release(new Response(JSON.stringify({success:true,data:{protocol:2,has_reference:true}})));await assert.rejects(stale,/Sesi akun berubah/);
 const source=fs.readFileSync(path.join(root,'api/proctoring.php'),'utf8');assert(!source.includes('jsonResponse('),'camera endpoints must use the API data envelope');assert(!source.includes("'reference_photo' =>"),'reference image must not be returned to the browser');
 const router=fs.readFileSync(path.join(root,'api.php'),'utf8');const exemptions=router.slice(router.indexOf('$csrfExemptActions'),router.indexOf('if (!$isMobileApp'));
 assert(!exemptions.includes('proctoring.log_event'));assert(!exemptions.includes('proctoring.swap_question'));
});

 test('component destruction stops camera and invalidates pending work',async()=>{const h=harness();await h.q.startQuiz();h.q.destroy();assert.equal(h.q.cameraStream,null);assert(h.track.stops>0);assert.equal(h.listeners.size,0);});
 test('expired submission retries after successful server verification',async()=>{const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.setSequence([verdict('RECHECK'),verdict('VERIFIED',true),verdict('VERIFIED',true)]);await h.q.autoSubmit();assert.equal(h.q._pendingCameraSubmit,true);assert(!h.events.some(e=>e.action==='attempt.submit'));await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.events.filter(e=>e.action==='attempt.submit').length,1);assert.equal(h.q.phase,'submitted');});

test('LPPAI VPS protocol captures nine guided frames and uses single frames only after verification',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='face_scan';const original=h.api.post;
 h.api.post=async(action,body)=>action==='proctoring.begin'?{session:'a'.repeat(64),nonce:'b'.repeat(64),capture_protocol:'pose-burst-v2',direction:'left'}:original(action,body);
 const pending=h.q._verifyCamera(h.video);await settle();
 for(let i=0;i<9;i++){assert.equal(h.q.scanDirection,i>=3&&i<6?'left':'');const entry=[...h.delayed].find(([,t])=>t.ms===1000);assert(entry);h.delayed.delete(entry[0]);entry[1].fn();await settle();}
 assert.equal((await pending).verified,true);const request=h.events.find(e=>e.action==='proctoring.verify');assert.equal(request.body.frames.length,9);assert.equal(new Set(request.body.frames).size,9);assert.equal(h.q._vpsMotionRequired,false);assert.equal(h.q.scanDirection,'');
 await h.q._verifyCamera(h.video);assert.equal(h.events.filter(e=>e.action==='proctoring.verify').at(-1).body.frames,undefined);
});

test('rightward motion guide clears on cancellation',async()=>{const h=harness();h.q.cameraStream=h.stream;h.q._vpsDirection='right';const pending=h.q._captureVpsMotion(h.video,h.q._cameraGeneration);await settle();for(let i=0;i<3;i++){const entry=[...h.delayed].find(([,t])=>t.ms===1000);h.delayed.delete(entry[0]);entry[1].fn();await settle();}assert.equal(h.q.scanDirection,'right');h.q.stopCamera();assert.equal(h.q.scanDirection,'');const entry=[...h.delayed].find(([,t])=>t.ms===1000);entry[1].fn();await assert.rejects(pending,/dibatalkan/);});
