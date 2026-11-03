// Requires Playwright and a local Chromium/Edge executable; never contacts production.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
(async()=>{
 const [alpine,tailwind]=await Promise.all(['https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js','https://cdn.tailwindcss.com'].map(async url=>(await fetch(url)).text()));
 const browser=await chromium.launch({headless:true,...(process.env.QUIZB_BROWSER?{executablePath:process.env.QUIZB_BROWSER}:{})});
 try {
 const page=await browser.newPage({viewport:{width:375,height:812}});const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const index=fs.readFileSync(path.join(root,'index.php'),'utf8');const start=index.lastIndexOf('<nav x-show=');
 let nav=index.slice(start,index.indexOf('</nav>',start)+6);
 const panel=mobile=>cp.execFileSync('php',['-n','-r',`$notificationPanelMobile=${mobile?'true':'false'};include '${root.replace(/\\/g,'/')}/pages/notification-panel.php';`],{encoding:'utf8'});
 nav=nav.replace(/<\?php \$notificationPanelMobile = true;[^?]+\?>/,panel(true));
 const markup='<aside class="hidden md:block fixed left-4 bottom-4"><div class="relative"><button data-notification-toggle @click="toggleNotifications($event.currentTarget)">Desktop bell</button>'+panel(false)+'</div></aside><main class="flex-1">QuizB test</main>'+nav;
 const entries=Array.from({length:80},(_,i)=>({id:80-i,type:'new_question',title:'Soal baru '+(80-i),body:'Materi baru di kuis Biologi Kelas X',link:'/quiz/11',is_read:false,created_at:'2026-11-03 10:00:00'}));
 const mock=`let entries=${JSON.stringify(entries)},offline=false;const store={get:(k,f)=>f};const debounce=f=>f;const api={getFull:async(a,p)=>{if(offline)throw Error('offline');return {data:entries.slice((p.page-1)*p.limit,p.page*p.limit),meta:{total:entries.length}}},get:async()=>({notifications:entries.filter(n=>!n.is_read).length,messages:0}),post:async(a,p)=>{entries.forEach(n=>{if(p.all||n.id===p.id)n.is_read=true});return {}},delete:async()=>{entries=entries.filter(n=>!n.is_read)}};`;
 const html='<!doctype html><html><head><meta charset="utf-8"><script>'+tailwind+';tailwind.config={darkMode:"class"};</script><style>'+fs.readFileSync(path.join(root,'assets/css/app.css'),'utf8')+fs.readFileSync(path.join(root,'assets/css/notifications.css'),'utf8')+'</style></head><body class="flex flex-col h-screen" x-data="Preview()">'+markup+'<script>'+mock+'</script><script>'+fs.readFileSync(path.join(root,'assets/js/app.v5.js'),'utf8')+'\nfunction Preview(){const a=QuizBApp();a.init=function(){this.user={id:1,name:"Test",role:"pelajar"};this.currentRoute="/dashboard";window.app=this;this.navigate=url=>window.destination=url};return a;}</script><script>'+alpine+'</script></body></html>';
 await page.route('http://quizb.test/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:html}));
 await page.goto('http://quizb.test/');await page.getByText('Notifikasi',{exact:true}).click();
 const mobile=page.locator('#notification-panel-mobile');await mobile.waitFor({state:'visible'});
 await page.waitForFunction(()=>app.notif.list.length===30&&!app.notif.loading);
 if(process.env.QUIZB_SCREENSHOTS){
  fs.mkdirSync(process.env.QUIZB_SCREENSHOTS,{recursive:true});
  await mobile.screenshot({path:path.join(process.env.QUIZB_SCREENSHOTS,'notifications-mobile.png')});
  await page.evaluate(()=>document.documentElement.classList.add('dark'));
  await mobile.screenshot({path:path.join(process.env.QUIZB_SCREENSHOTS,'notifications-dark.png')});
  await page.evaluate(()=>document.documentElement.classList.remove('dark'));
 }
 assert.equal(await mobile.locator('.notification-body').first().textContent(),'Materi baru di kuis Biologi Kelas X');
 for(const width of [320,375,430]){
  await page.setViewportSize({width,height:812});
  assert(await mobile.evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth}),`Panel fits viewport ${width}`);
 }
 await mobile.getByRole('button',{name:'Muat notifikasi sebelumnya'}).click();
 await page.waitForFunction(()=>app.notif.list.length===60&&!app.notif.loading);
 await page.evaluate(()=>offline=true);await mobile.getByRole('button',{name:'Segarkan'}).click();
 await mobile.getByRole('alert').waitFor({state:'visible'});
 await page.evaluate(()=>offline=false);await mobile.getByRole('button',{name:'Coba lagi'}).click();
 await page.waitForFunction(()=>!app.notif.error&&!app.notif.loading);
 await mobile.locator('.notification-item').first().focus();await page.keyboard.press('Enter');
 await page.waitForFunction(()=>window.destination==='/quiz/11'&&!app.notif.show);
 await page.getByText('Notifikasi',{exact:true}).click();await page.waitForFunction(()=>!app.notif.loading);
 await mobile.getByRole('button',{name:'Baca semua',exact:true}).click();
 await page.waitForFunction(()=>!app.notif.busy&&app.notif.unreadCount===0);
 await mobile.getByRole('button',{name:'Hapus yang dibaca',exact:true}).click();
 await page.waitForFunction(()=>!app.notif.busy&&app.notif.list.length===0);
 await mobile.getByRole('heading',{name:'Semua sudah beres'}).waitFor({state:'visible'});
 await mobile.getByRole('button',{name:'Tutup notifikasi'}).click();
 await page.setViewportSize({width:1280,height:900});await page.getByRole('button',{name:'Desktop bell'}).click();
 const desktop=page.locator('#notification-panel-desktop');await desktop.waitFor({state:'visible'});
 await desktop.getByRole('button',{name:'Tutup notifikasi'}).focus();await page.keyboard.press('Escape');
 await page.waitForFunction(()=>!app.notif.show);
 assert.equal(await page.evaluate(()=>document.activeElement.textContent),'Desktop bell');
 await page.evaluate(()=>document.documentElement.classList.add('dark'));await page.getByRole('button',{name:'Desktop bell'}).click();
 assert.deepEqual(errors,[]);
 console.log('PASS: actual mobile/desktop markup, 320/375/430px bounds, body, pagination, error/retry, keyboard activation, mark-all, delete-read, empty state, Escape and focus restoration, dark mode; no browser errors.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
