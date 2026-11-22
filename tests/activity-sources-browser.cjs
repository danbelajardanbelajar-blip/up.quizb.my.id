const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_DIR || 'playwright');
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const types = ['assignment', 'independent', 'challenge', 'tournament', undefined];
    const labels = ['Tugas Kelas', 'Kuis Umum', 'Tantangan', 'Turnamen', 'Asal belum diketahui'];
    const rows = types.map((type, i) => ({id: i+1, user_id: i+1, user_name: 'Peserta '+(i+1), quiz_id: 10, quiz_title: 'Kuis Contoh', mode: 'exam', score: 80, badge: 'good', time_taken: 30, completed_at: '2026-11-22T10:00:00', source_type: type, source_label: type ? labels[i] : undefined, source_detail: type ? 'Keterangan '+labels[i] : undefined}));
    await page.setContent('<main x-data="fixture()">'+fs.readFileSync('pages/activity.html', 'utf8')+'</main>');
    await page.addScriptTag({content: `window.store={get:(key,fallback)=>fallback};window.debounce=fn=>fn;window.formatRelative=()=> 'baru saja';window.formatDayLabel=()=> 'Minggu';window.formatTime=()=> '10:00';window.opened=[];`});
    await page.addScriptTag({path: require('node:path').resolve('assets/js/app.v5.js')});
    await page.addScriptTag({content: `window.fixture=()=>{const app=QuizBApp();app.init=()=>{};app.activity={loading:false,list:${JSON.stringify(rows)},total:5,page:1,loadingMore:false};app.openPublicHistory=(...args)=>window.opened.push(args);return app;};`});
    await page.addScriptTag({path: process.env.ALPINE_JS});
    await page.locator('[aria-label="Asal pengerjaan: Tugas Kelas"]').waitFor({state:'visible'});
    for (const label of labels) {
      const badge = page.locator('[aria-label="Asal pengerjaan: '+label+'"]');
      assert.equal(await badge.count(), 1);
      assert.equal(await badge.isVisible(), true);
      assert.equal(await badge.textContent(), label);
      assert.ok(await badge.getAttribute('title'));
    }
    assert.match(await page.locator('[aria-label="Asal pengerjaan: Tugas Kelas"]').getAttribute('class'), /bg-blue-50/);
    await page.getByRole('button', {name:'Peserta 1',exact:true}).click();
    await page.getByRole('button', {name:'Kuis Contoh',exact:true}).first().click();
    await page.getByRole('button', {name:'Ujian'}).first().click();
    assert.deepEqual((await page.evaluate(()=>window.opened)).map(call=>call[0]), ['user','quiz','mode']);
    assert.deepEqual(errors, []);
    console.log('PASS activity production template: five source labels, accessible descriptions, badge styles, existing history actions and no Alpine errors at mobile viewport');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
