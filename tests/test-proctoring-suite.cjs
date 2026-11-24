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
 const api={get:async(action,params)=>{events.push({action,params});if(action==='proctoring.check_reference'){if(refError)throw Error('Reference API failed');return{protocol:2,has_reference:reference};}if(action==='quiz.questions')return{quiz:{id:19,require_camera:1},questions:[{id:1,options:[]}]};return{};},post:async(action,body)=>{events.push({action,body});if(action==='proctoring.begin')return{session:'a'.repeat(64),nonce:'b'.repeat(64),capture_protocol:body.entry_mode==='background'?'vps-single-v1':undefined};if(action==='proctoring.verify')return sequence.shift()||{status:'VERIFIED',verified:true,next_nonce:('c'+frames.toString(16).padStart(63,'0')).slice(0,64),message:'Verified by server'};if(action==='proctoring.save_reference')return{success:true};if(action==='attempt.submit')return{attempt_id:123,score:80};return{success:true};}};
 ctx.api=api;vm.createContext(ctx);vm.runInContext(engineCode,ctx);const q=ctx.QuizEngine();q.quiz={id:19,require_camera:1};q.assignmentId=42;q.phase='ready';q.questions=[{id:1,options:[{id:2}]}];q.answers={};q.timeLeft=100;q.$refs={faceScanVideo:video,proctorPipVideo:video,proctorFreezeVideo:video};q.$nextTick=fn=>fn();q.startBgMusic=()=>{};q.stopBgMusic=()=>{};q.startProctoringAudio=()=>{};q.initFullscreenListeners=()=>{};q.removeFullscreenListeners=()=>{};
 return{q,ctx,api,Q,stream,track,listeners,video,timers,delayed,events,setSequence:values=>{sequence=values;}};
}
const verdict=(status,verified=false,n='d')=>({status,verified,next_nonce:n.repeat(64),message:status});

test('returning participant sees questions immediately while identity verification runs on the server',async()=>{
 const h=harness();h.q.questions=[];h.q._cameraQuestionsPending=true;h.q._questionLoadParams={id:19,assignment_id:42};let finish;const post=h.api.post;
 h.api.post=async(action,body)=>action==='proctoring.verify'?new Promise(resolve=>finish=resolve):post(action,body);
 await h.q.startQuiz();await settle();assert.equal(h.Q.inits,1);assert.equal(h.q.phase,'playing');assert.equal(h.q.questions.length,1);assert.equal(h.q._lastServerVerified,0);assert.equal(h.q._vpsMotionRequired,false);assert.equal(h.q._scanInterval,null);
 assert.equal(h.events.find(e=>e.action==='proctoring.begin').body.entry_mode,'background');assert(!h.events.some(e=>e.action==='proctoring.log_event'));
 finish(verdict('VERIFIED',true));await settle();assert(h.q._lastServerVerified>0);assert.equal(h.q.showFaceWarning,false);
});
test('returning participant background mismatch blocks answering until server verification succeeds',async()=>{
 const h=harness();h.setSequence([verdict('RECHECK'),verdict('VERIFIED',true)]);await h.q.startQuiz();await settle();assert.equal(h.q.phase,'playing');assert.equal(h.q.showFaceWarning,true);h.q.selectOption(1,2);assert.equal(h.q.answers[1],undefined);
 await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.q.showFaceWarning,false);h.q.selectOption(1,2);assert.equal(h.q.answers[1],2);
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
 await h.q.checkFaceIdentity();assert.equal(h.q.showFaceWarning,true);h.q.selectOption(1,2);assert.equal(h.q.answers[1],undefined);h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,100);
 h.Q.frame={ready:true};h.setSequence([verdict('RECHECK'),verdict('VERIFIED',true)]);await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.q.showFaceWarning,true);await h.timers.get(h.q._fastFaceCheckInterval).fn();assert.equal(h.q.showFaceWarning,false);h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,99);
});

test('invalid or truthy string verdict never unlocks the exam',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.setSequence([verdict('VERIFIED','true')]);await h.q.checkFaceIdentity();assert.equal(h.q.showFaceWarning,true);assert.equal(h.q._lastServerVerified,0);
});

test('camera ended event stops the stream and preserves answers/time for recovery',async()=>{
 const h=harness();await h.q.startQuiz();h.q.phase='playing';h.q.answers={1:2};h.q.timeLeft=77;h.listeners.get('ended')();assert.equal(h.q.phase,'camera_error');assert.equal(h.q.answers[1],2);assert.equal(h.q.timeLeft,77);assert.equal(h.q.cameraStream,null);assert.equal(h.listeners.size,0);assert(h.track.stops>0);
});

test('unready or stopped camera never yields fabricated image data',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.track.readyState='ended';await assert.rejects(h.q._cameraVideo(),/Kamera/);assert(!h.events.some(e=>e.action==='proctoring.verify'));
});

test('late camera permission result is stopped after navigation or cancellation',async()=>{
 const h=harness();let release;h.ctx.navigator.mediaDevices.getUserMedia=()=>new Promise(r=>release=r);const pending=h.q.startQuiz();await settle();h.q.stopCamera();release(h.stream);await pending;assert.equal(h.q.cameraStream,null);assert(h.track.stops>0);assert(!h.events.some(e=>e.action==='proctoring.check_reference'));
});

test('late server response cannot revive an invalidated camera generation',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q._proctorSession='a'.repeat(64);h.q._proctorNonce='b'.repeat(64);let release;h.api.post=()=>new Promise(r=>release=r);const request=h.q._verifyCamera(h.video);await settle();h.q.stopCamera();release(verdict('VERIFIED',true));const response=await request;assert.equal(response.status,'CANCELLED');assert.equal(h.q._lastServerVerified,0);
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
 for(let i=0;i<9;i++){assert.equal(h.q.scanDirection,i>=3&&i<6?'right':'');const entry=[...h.delayed].find(([,t])=>t.ms===1000);assert(entry);h.delayed.delete(entry[0]);entry[1].fn();await settle();}
 assert.equal((await pending).verified,true);const request=h.events.find(e=>e.action==='proctoring.verify');assert.equal(request.body.frames.length,9);assert.equal(new Set(request.body.frames).size,9);assert.equal(h.q._vpsMotionRequired,false);assert.equal(h.q.scanDirection,'');
 await h.q._verifyCamera(h.video);assert.equal(h.events.filter(e=>e.action==='proctoring.verify').at(-1).body.frames,undefined);
});

test('rightward motion guide clears on cancellation',async()=>{const h=harness();h.q.cameraStream=h.stream;h.q._vpsDirection='right';const pending=h.q._captureVpsMotion(h.video,h.q._cameraGeneration);await settle();for(let i=0;i<3;i++){const entry=[...h.delayed].find(([,t])=>t.ms===1000);h.delayed.delete(entry[0]);entry[1].fn();await settle();}assert.equal(h.q.scanDirection,'left');h.q.stopCamera();assert.equal(h.q.scanDirection,'');const entry=[...h.delayed].find(([,t])=>t.ms===1000);entry[1].fn();await assert.rejects(pending,/dibatalkan/);});

function noiseHarness(){const h=harness();let volume=0;h.q.phase='playing';h.q.cameraStream=h.stream;h.q._audioData=new Uint8Array(256);h.q._audioAnalyser={getByteFrequencyData:data=>data.fill(volume)};h.q.questions=[{id:1,options:[{id:2},{id:3}]},{id:9,options:[{id:10}]}];h.q.answers={1:2,9:10};h.volume=v=>volume=v;return h;}
test('noise threshold is strictly above 80; continuous loud sound stays blocked and quiet resumes immediately',async()=>{
 const h=noiseHarness();h.volume(80);for(let i=0;i<30;i++)h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,false);
 h.volume(81);for(let i=0;i<10;i++)h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,false);h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,true);await settle();
 assert.equal(h.events.filter(e=>e.action==='proctoring.swap_question').length,1);assert.equal(h.q.answers[1],undefined);assert.equal(h.q.answers[9],10);assert.equal(h.q.questions[0].options.length,2);
 h.q.startTimer();h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,100);for(let i=0;i<200;i++)h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,true);assert.equal(h.events.filter(e=>e.action==='proctoring.swap_question').length,1);
 h.volume(80);h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,false);h.timers.get(h.q.timerInterval).fn();assert.equal(h.q.timeLeft,99);
});
test('quiet recovery waits only for pending replacement, then unlocks without a countdown',async()=>{
 const h=noiseHarness();let release;h.api.post=()=>new Promise(resolve=>release=resolve);h.volume(90);for(let i=0;i<11;i++)h.q.checkAmbientNoise();await settle();h.volume(10);h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,true);assert.equal(h.q._noiseSwapPending,true);
 release({swapped:true,new_question:{id:7,options:[{id:8}]}});await settle();assert.equal(h.q.isNoiseFrozen,false);assert.equal(h.q._noiseSwapPending,false);assert.equal(h.q.questions[0].id,7);assert.equal(h.q.answers[9],10);
});
test('network failure reshuffles options locally and cannot leave quiet participants locked',async()=>{
 const h=noiseHarness();h.api.post=async()=>{throw Error('offline')};h.volume(90);for(let i=0;i<11;i++)h.q.checkAmbientNoise();await settle();assert.equal(h.q.isNoiseFrozen,true);assert.equal(h.q._noiseSwapPending,false);assert.equal(h.q.questions[0].id,1);assert.notEqual(h.q.questions[0].options[0].id,2);
 h.volume(0);h.q.checkAmbientNoise();assert.equal(h.q.isNoiseFrozen,false);
});
test('late noise replacement cannot change another camera session or duplicate an active question id',async()=>{
 const h=noiseHarness();h.api.post=async()=>({swapped:true,new_question:{id:9,options:[{id:11}]}});h.volume(90);for(let i=0;i<11;i++)h.q.checkAmbientNoise();await settle();assert.equal(h.q.questions[0].id,1);assert.equal(new Set(h.q.questions.map(q=>q.id)).size,2);
 const other=noiseHarness();let release;other.api.post=()=>new Promise(resolve=>release=resolve);other.volume(90);for(let i=0;i<11;i++)other.q.checkAmbientNoise();await settle();other.q.stopCamera();release({swapped:true,new_question:{id:77,options:[]}});await settle();assert.equal(other.q.questions[0].id,1);assert.equal(other.q.isNoiseFrozen,false);
});

test('first-time enrollment still uses the server motion challenge before starting questions',async()=>{
 const h=harness({reference:false});let clock=1000;h.ctx.Date={now:()=>clock};const post=h.api.post;let mode;
 h.api.post=async(action,body)=>{if(action==='proctoring.begin'){mode=body.entry_mode;return{session:'a'.repeat(64),nonce:'b'.repeat(64),capture_protocol:'pose-burst-v2',direction:'left'};}return post(action,body);};
 await h.q.startQuiz();await settle();assert.equal(h.q.phase,'face_scan');const tick=h.timers.get(h.q._scanInterval).fn;await tick();clock+=2100;const scan=tick();await settle();assert.equal(mode,'motion');assert.equal(h.q.phase,'face_scan');
 for(let i=0;i<9;i++){const entry=[...h.delayed].find(([,t])=>t.ms===1000);assert(entry);h.delayed.delete(entry[0]);entry[1].fn();await settle();}
 await scan;assert.equal(h.q.phase,'playing');assert.equal(h.events.filter(e=>e.action==='proctoring.save_reference').length,1);
});


test('periodic identity checks never accumulate images or submit a snapshot archive',async()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.phase='playing';h.q._lastServerVerified=Date.now();
 for(let i=0;i<40;i++)await h.q.checkFaceIdentity();
 assert.equal(h.q.snapshots,undefined);assert(!h.events.some(e=>e.action==='attempt.upload_snapshot'));
 h.q.answers={1:2};await h.q.submitAnswers();const payload=h.events.find(e=>e.action==='attempt.submit').body;
 assert.equal(Object.hasOwn(payload,'snapshots'),false);assert(h.events.some(e=>e.action==='proctoring.verify'));
});

test('noise and browser-event reports contain metadata only, without camera images',async()=>{
 const h=noiseHarness();h.q.initFullscreenListeners=undefined;
 // Use actual listener registration rather than the harness no-op.
 const original=vm.runInContext('QuizEngine()',h.ctx);original.quiz={id:19};original.phase='playing';
 original.cameraStream=h.stream;original.initFullscreenListeners();original._fullscreenListener();
 h.ctx.document.hidden=true;original._visibilityListener();
 h.api.post=async(action,body)=>{h.events.push({action,body});return {swapped:false};};
 await h.q.triggerNoisePenalty();
 for(const event of h.events.filter(e=>['proctoring.log_event','proctoring.swap_question'].includes(e.action))) assert.equal(Object.hasOwn(event.body,'snapshot'),false);
 assert.equal(h.events.filter(e=>e.action==='proctoring.log_event').length,2);
});

test('stopping camera cancels periodic verification interval and startup timeout',()=>{
 const h=harness();h.q.cameraStream=h.stream;h.q.startFaceVerificationLoop();
 const interval=h.q.faceVerificationInterval,timeout=h.q.faceVerificationTimeout;
 assert(h.timers.has(interval));assert(h.delayed.has(timeout));h.q.stopCamera();
 assert(!h.timers.has(interval));assert(!h.delayed.has(timeout));
});

test('in-task face checks accept all four corners while guided capture still requires the center', () => {
 const video = {videoWidth:640, videoHeight:480, readyState:4};
 let faces = [], brightness = 128, sourceDrawn;
 const canvasContext = {
  drawImage: (...args) => { sourceDrawn = args; },
  getImageData: (_x, _y, width, height) => ({data:new Uint8ClampedArray(width * height * 4).fill(brightness)}),
 };
 const context = {window:{}, document:{createElement:() => ({getContext:() => canvasContext})}};
 vm.createContext(context);
 vm.runInContext(verifierCode, context);
 const verifier = context.window.QuizFaceVerifier;
 verifier.cascadeClassifier = (row, col, scale) => faces.some(
  ([y, x, size = 60]) => row === y && col === x && Math.abs(scale - size) < 1
 ) ? 5 : -1;
 const inTask = {requireCentered:false};

 for (const face of [[37,37], [37,199], [139,37], [139,199]]) {
  faces = [face];
  assert.match(verifier.evaluateFrame(video).label, /Posisikan Wajah/);
  assert.equal(verifier.evaluateFrame(video, inTask).ready, true);
 }
 assert.deepEqual(sourceDrawn, [video, 0, 0, 240, 180]);

 faces = [];
 assert.match(verifier.evaluateFrame(video, inTask).label, /Tidak Terdeteksi/);
 faces = [[37,37], [139,199]];
 assert.match(verifier.evaluateFrame(video, inTask).label, /Lebih dari 1 Orang/);
 faces = [[40,40,30]];
 assert.match(verifier.evaluateFrame(video, inTask).label, /Terlalu Jauh/);
 brightness = 20;
 assert.match(verifier.evaluateFrame(video, inTask).label, /Kurang Terang/);
});

test('monitoring, warning recovery and final submission accept an off-center face with server verification', async () => {
 const h = harness();
 h.q.cameraStream = h.stream;
 h.q.phase = 'playing';
 h.q.answers = {1:2};
 let faceVisible = true;
 h.Q.evaluateFrame = (_video, {requireCentered = true} = {}) => ({
  ready: faceVisible && !requireCentered,
  label: faceVisible ? 'OFF_CENTER' : 'NO_FACE',
 });

 await h.q.checkFaceIdentity();
 assert.equal(h.q.showFaceWarning, false);

 faceVisible = false;
 await h.q.checkFaceIdentity();
 assert.equal(h.q.showFaceWarning, true);

 faceVisible = true;
 h.setSequence([verdict('RECHECK'), verdict('VERIFIED', true)]);
 const recover = h.timers.get(h.q._fastFaceCheckInterval).fn;
 await recover();
 assert.equal(h.q.showFaceWarning, true);
 await recover();
 assert.equal(h.q.showFaceWarning, false);

 await h.q.submitAnswers();
 assert.equal(h.q.phase, 'submitted');
 const requests = h.events.filter(event => event.action === 'proctoring.verify');
 assert.deepEqual(requests.map(event => event.body.purpose), ['periodic', 'periodic', 'periodic', 'final']);
 assert.equal(h.events.filter(event => event.action === 'attempt.submit').length, 1);
});
