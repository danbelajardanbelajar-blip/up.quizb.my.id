const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let requests=[],notices=[],accepted=true,finish;
const ctx={console,URL,URLSearchParams,store:{get:(k,f)=>f},debounce:f=>f,window:{location:{hash:'',origin:'https://quizb.my.id'}},document:{getElementById:()=>null},sessionStorage:{getItem:()=>null,setItem:()=>{}},setTimeout:()=>0,clearTimeout:()=>{},setInterval:()=>0,clearInterval:()=>{},confirm:()=>accepted,api:{post:async(action,body)=>{requests.push({action,body});return{message:'Reset complete'};}}};
vm.createContext(ctx);vm.runInContext(fs.readFileSync('assets/js/app.v5.js','utf8'),ctx);const app=ctx.QuizBApp();app.showToast=(...args)=>notices.push(args);
(async()=>{
 await app.resetStudentExamPhoto({id:7,name:'Student'},3);assert.equal(requests[0].action,'proctoring.reset_reference');assert.equal(requests[0].body.class_id,3);assert.equal(requests[0].body.user_id,7);assert.equal(app.resetExamPhotoUserId,null);
 await app.resetStudentExamPhoto({id:7,name:'Student'});assert.equal(requests[1].body.class_id,undefined);
 accepted=false;await app.resetStudentExamPhoto({id:7,name:'Student'},3);assert.equal(requests.length,2);accepted=true;
 ctx.api.post=async()=>new Promise(resolve=>finish=resolve);const pending=app.resetStudentExamPhoto({id:7,name:'Student'},3);assert.equal(app.resetExamPhotoUserId,7);await app.resetStudentExamPhoto({id:8,name:'Other'},3);finish({});await pending;assert.equal(app.resetExamPhotoUserId,null);
 ctx.api.post=async()=>{throw Error('Access denied')};await app.resetStudentExamPhoto({id:7,name:'Student'},3);assert.equal(app.resetExamPhotoUserId,null);assert.equal(notices.at(-1)[1],'error');
 console.log('PASS class scope, existing admin menu, cancellation, single flight, success/error busy cleanup');
})().catch(error=>{console.error(error);process.exitCode=1;});
