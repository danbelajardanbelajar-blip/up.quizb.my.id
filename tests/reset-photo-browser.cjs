const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_DIR||'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
 try {
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const source=fs.readFileSync('pages/classroom-detail.html','utf8');const start=source.indexOf('<template x-for="member in classroom.members"');const end=source.indexOf('<div x-show="classroom.members.length === 0"',start);
  assert(start>=0&&end>start);await page.setContent('<main x-data="fixture()">'+source.slice(start,end)+'</main>');
  await page.addScriptTag({content:`window.store={get:(key,fallback)=>fallback};window.debounce=fn=>fn;window.confirm=()=>true;window.requests=[];window.api={post:(action,body)=>{window.requests.push({action,body});return new Promise(resolve=>window.finishReset=resolve);}};`});
  await page.addScriptTag({path:require('node:path').resolve('assets/js/app.v5.js')});
  await page.addScriptTag({content:`window.fixture=()=>{const app=QuizBApp();app.init=()=>{};app.user={id:8,role:'pengajar'};app.classroom.isTeacher=true;app.classroom.detail={id:3};app.classroom.members=[{id:7,name:'Test Siswa',email:'student@example.invalid'}];app.showToast=()=>{};return app;};`});
  await page.addScriptTag({path:process.env.ALPINE_JS});const button=page.getByRole('button',{name:'Reset foto acuan wajah Test Siswa'});await button.waitFor({state:'visible'});await button.click();await page.waitForFunction(()=>window.requests.length===1);assert.equal(await button.isDisabled(),true);assert.equal((await page.evaluate(()=>window.requests[0])).body.class_id,3);
  await page.evaluate(()=>window.finishReset({message:'Reset complete'}));await page.waitForFunction(()=>Alpine.$data(document.querySelector('main')).resetExamPhotoUserId===null);assert.equal(await button.isDisabled(),false);
  await page.evaluate(()=>{const app=Alpine.$data(document.querySelector('main'));app.user.role='pelajar';app.classroom.isTeacher=false;});await button.waitFor({state:'detached'});
  await page.evaluate(()=>{const app=Alpine.$data(document.querySelector('main'));app.user.role='pengajar';app.classroom.isTeacher=false;});assert.equal(await button.count(),0);
  await page.evaluate(()=>Alpine.$data(document.querySelector('main')).user.role='admin');await button.waitFor({state:'visible'});
  assert.deepEqual(errors,[]);console.log('PASS production member-row template: teacher action, scoped payload, busy state, student/non-owner hidden, admin supported; no Alpine errors');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
