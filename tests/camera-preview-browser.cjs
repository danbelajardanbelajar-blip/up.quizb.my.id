const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_DIR || 'playwright');
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage({viewport:{width:390,height:844}});
    const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.setContent(fs.readFileSync('pages/quiz-engine.html','utf8'));
    await page.addStyleTag({path:path.resolve('assets/css/app.css')});
    // Essential Tailwind positioning for an offline fixture; flash animation uses production CSS.
    await page.addStyleTag({content:'.relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}.bg-white{background:white}.z-20{z-index:20}.w-72{width:288px}.h-96{height:384px}.w-full{width:100%}.h-full{height:100%}'});
    await page.addScriptTag({content:`window.formatTime=()=> '00:00';window.saved=[];window.failSave=false;window.api={get:async()=>({}),post:async(action,body)=>{if(action==='proctoring.save_reference'){window.saved.push(body.photo);return {success:!window.failSave,message:'Penyimpanan uji gagal'};}throw Error('Unexpected API '+action);}};`});
    await page.addScriptTag({path:path.resolve('assets/js/quiz-face-verifier.js')});
    await page.addScriptTag({path:path.resolve('assets/js/quiz-engine.v6.js')});
    await page.addScriptTag({path:process.env.ALPINE_JS});
    await page.waitForFunction(()=>!!document.querySelector('[x-data]')._x_dataStack);
    await page.evaluate(async()=>{
      const q=Alpine.$data(document.querySelector('[x-data]'));
      q.quiz={id:1,require_camera:1};
      const canvas=document.createElement('canvas');canvas.width=640;canvas.height=480;
      const ctx=canvas.getContext('2d');ctx.fillStyle='rgb(20,180,60)';ctx.fillRect(0,0,640,480);
      q.cameraStream=canvas.captureStream(15);
      window.QuizFaceVerifier.evaluateFrame=()=>({ready:true,color:'green'});
      q._vpsDirection='left';
      q._verifyCamera=async function(video){window.motionFrames=await this._captureVpsMotion(video,this._cameraGeneration);return {verified:true,status:'VERIFIED',message:'Uji berhasil'};};
      q.startActualQuiz=function(){this.phase='playing';};
      q.startHandsFreeFaceScan(true);
    });
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[x-data]')).scanCaptured===true,{},{timeout:10000});
    await page.waitForTimeout(350);
    const assertPreview = async () => {
      const state=await page.evaluate(()=>{const video=document.getElementById('faceScanVideo');const flash=document.querySelector('[data-camera-capture-flash]');return {opacity:flash?getComputedStyle(flash).opacity:null,width:video.videoWidth,ready:video.readyState,paused:video.paused};});
      assert.equal(state.opacity,'0');assert.equal(state.width,640);assert.ok(state.ready>=2);assert.equal(state.paused,false);
    };
    await assertPreview();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[x-data]')).scanStatusMessage.includes('Langkah 2/3'),{},{timeout:6000});
    await assertPreview();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[x-data]')).scanStatusMessage.includes('Langkah 3/3'),{},{timeout:6000});
    await assertPreview();
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[x-data]')).phase==='playing',{},{timeout:6000});
    assert.equal(await page.evaluate(()=>window.saved.length),1);
    assert.equal(await page.evaluate(()=>window.motionFrames.length),9);
    // Decode actual snapshots: white overlay must not contaminate the captured camera pixels.
    const pixel=await page.evaluate(async()=>{const img=new Image();img.src=window.motionFrames[0];await img.decode();const c=document.createElement('canvas');c.width=1;c.height=1;const ctx=c.getContext('2d');ctx.drawImage(img,0,0,1,1);return [...ctx.getImageData(0,0,1,1).data];});
    assert.ok(pixel[1]>150 && pixel[0]<50 && pixel[2]<90);
    // Reduced motion and missing animation stylesheet must both remain transparent.
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.evaluate(()=>{const q=Alpine.$data(document.querySelector('[x-data]'));q.phase='face_scan';q.scanCaptured=false;});
    await page.waitForFunction(()=>!document.querySelector('[data-camera-capture-flash]'));
    await page.evaluate(()=>Alpine.$data(document.querySelector('[x-data]')).scanCaptured=true);
    await assertPreview();
    await page.addStyleTag({content:'.camera-capture-flash{animation:none!important}'});
    await assertPreview();
    // Retrying clears the flash; failed storage must not announce a successful capture.
    await page.evaluate(()=>{window.failSave=true;Alpine.$data(document.querySelector('[x-data]')).startHandsFreeFaceScan(true);});
    await page.waitForFunction(()=>!document.querySelector('[data-camera-capture-flash]'));
    await page.waitForFunction(()=>Alpine.$data(document.querySelector('[x-data]')).scanStatusMessage==='Penyimpanan uji gagal',{},{timeout:10000});
    assert.equal(await page.locator('[data-camera-capture-flash]').count(),0);
    await page.evaluate(()=>{Alpine.$data(document.querySelector('[x-data]')).stopCamera();document.querySelector('[x-data]').remove();});
    await page.waitForTimeout(50);
    assert.deepEqual(errors,[]);
    console.log('PASS real camera preview + enrollment UI: flash transparent after capture and throughout 3 motion stages, 9 valid snapshots, retry, save failure, reduced motion, animation unavailable and teardown; synthetic stream/mocked verification, no participant photos');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
