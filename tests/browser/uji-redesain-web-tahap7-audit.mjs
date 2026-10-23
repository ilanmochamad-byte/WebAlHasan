// Probe independen Codex: DB Tahap 7 BARU, server localhost 8977.
// Mengubah nilai/sikap sintetis. Jalankan ulang fixture sebelum mengulang.
import { chromium } from 'playwright';
import { readFileSync, existsSync } from 'node:fs';
const base = process.env.BASE_URL || 'http://127.0.0.1:8977';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw Error('Hanya localhost');
const ids = JSON.parse(readFileSync(new URL('../../storage/private/v4/redesain_t7_ids.json', import.meta.url)));
let pass = 0, fail = 0;
const cek = (ok, label) => { ok ? pass++ : fail++; console.log(`[${ok ? 'lulus' : 'gagal'}] ${label}`); };
const browser = await chromium.launch();
async function buka(user, width, js) {
  const ctx = await browser.newContext({viewport:{width,height:900},javaScriptEnabled:js});
  await ctx.route('**/*', r => {
    const u = new URL(r.request().url());
    if (u.origin === base) return r.continue();
    const file = /bootstrap.*css/.test(u.href) ? 'bootstrap/dist/css/bootstrap.min.css'
      : /bootstrap.*js/.test(u.href) ? 'bootstrap/dist/js/bootstrap.bundle.min.js'
      : /all.min.css/.test(u.href) ? '@fortawesome/fontawesome-free/css/all.min.css'
      : /\/webfonts\//.test(u.href) ? '@fortawesome/fontawesome-free/webfonts/'+u.pathname.split('/').pop() : '';
    const p = new URL('./node_modules/'+file, import.meta.url);
    return file && existsSync(p) ? r.fulfill({body:readFileSync(p),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'font/woff2'}) : r.abort();
  });
  const page = await ctx.newPage();
  await page.goto(base+'/portal/index.php');
  await page.fill('#username',user); await page.fill('#password','Sandbox#123');
  await Promise.all([page.waitForURL('**/portal/index.php'),page.getByRole('button',{name:'Masuk',exact:true}).click()]);
  return {ctx,page};
}
try {
  for (const js of [true,false]) for (const width of [375,768,1280]) {
    const suffix = `${width}px JS=${js}`;
    // Setiap lebar memakai santri berbeda; keenamnya awalnya draf lengkap.
    const i = 13 + [375,768,1280].indexOf(width) + (js ? 0 : 3);
    const {ctx,page} = await buka('sbx_guru_mapel',width,js);
    const url = `${base}/portal/nilai_semester.php?rencana_mapel_id=${ids.rencana_mapel[ids.mapel.fiqih]}&santri_id=${ids.santri[i]}`;
    await page.goto(url);
    const f = page.locator('#panel-nilai');
    cek(await f.evaluate(f=>f.checkValidity()), `F-1 nilai: alasan koreksi kosong tidak memblokir serahkan ${suffix}`);
    const response = page.waitForNavigation({timeout:2500}).catch(()=>null);
    await f.getByRole('button',{name:'Serahkan',exact:true}).click();
    const sent = await response;
    cek(!!sent, `F-1 nilai: klik Serahkan benar-benar POST ${suffix}`);
    if (sent) { await page.waitForLoadState(); cek((await page.locator('#panel-nilai').getByRole('button',{name:'Serahkan',exact:true}).count())===0,`F-1 nilai: status menjadi diserahkan ${suffix}`); }
    // Koreksi tetap wajib alasan: tanpa alasan tidak boleh mengubah nilai.
    const correctionUrl = page.url();
    await page.locator('#panel-nilai input[name$="[nilai]"]').first().fill('77');
    await page.locator('#koreksi-nilai > summary').click();
    const correction = page.getByRole('button',{name:'Simpan koreksi',exact:true});
    if (js) {
      await correction.click();
      cek(await page.locator('#alasan-koreksi').isVisible() && page.url()===correctionUrl,`F-1 koreksi: alasan kosong ditahan di dialog ${suffix}`);
    } else {
      const [rejected] = await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),correction.click()]);
      cek(rejected.status()===422 && (await page.locator('#panel-nilai input[name$="[nilai]"]').first().inputValue())!=='77.00',`F-1 koreksi: tanpa JS tetap ditolak server ${suffix}`);
      await page.locator('#koreksi-nilai > summary').click();
      await page.locator('#panel-nilai input[name$="[nilai]"]').first().fill('77');
    }
    await page.locator('#alasan-koreksi').fill('Koreksi capaian sintetis auditor');
    await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Simpan koreksi',exact:true}).click()]);
    cek((await page.locator('#panel-nilai input[name$="[nilai]"]').first().inputValue())==='77.00',`F-1 koreksi: dengan alasan berhasil ${suffix}`);
    await ctx.close();
  }
  for (const js of [true,false]) {
    const {ctx,page} = await buka('sbx_murobi_c',375,js);
    const sid = ids.santri[js?11:12];
    await page.goto(`${base}/portal/sikap_semester.php?mode=murobi&rencana_id=${ids.rencana}&santri_id=${sid}`);
    cek(await page.locator('#panel-sikap').evaluate(f=>f.checkValidity()),`F-1 sikap: tanpa alasan koreksi boleh diketahui JS=${js}`);
    const sent = page.waitForNavigation({timeout:2500}).catch(()=>null);
    await page.locator('#panel-sikap').getByRole('button',{name:'Tandai diketahui',exact:true}).click();
    cek(!!await sent,`F-1 sikap: klik Tandai diketahui POST JS=${js}`);
    await page.waitForLoadState();
    cek((await page.locator('.ah-panel').innerText()).includes('Diketahui SBX Murobi C'),`F-1 sikap: pengetahuan murobi tersimpan JS=${js}`);
    await ctx.close();
  }
} finally { await browser.close(); }
console.log(`Ringkasan: ${pass} lulus, ${fail} gagal`);
process.exitCode = fail ? 1 : 0;
