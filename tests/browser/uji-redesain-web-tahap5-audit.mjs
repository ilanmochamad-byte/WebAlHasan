/** Probe browser auditor: pencarian pilihan V-6 dan tautan sumber V-5.
 * BASE_URL=http://127.0.0.1:8981 node tests/browser/uji-redesain-web-tahap5-audit.mjs
 * Hanya pembacaan; tidak mengubah data bisnis. */
import { chromium } from 'playwright';
import { readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8981';
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('Uji hanya untuk server lokal 127.0.0.1');

let lulus = 0;
let gagal = 0;
const galat = [];
const bawaan = [];
function cek(ok, label, ket = '') {
  ok ? lulus++ : gagal++;
  console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}${!ok && ket ? ' — ' + ket : ''}`);
}
const CDN = [
  [/bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'],
  [/bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'],
  [/font-awesome.*all\.min\.css/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css'],
];
async function siapkan(context) {
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === BASE) return route.continue();
    for (const [re, berkas, tipe] of CDN) {
      const jalur = new URL('./node_modules/' + berkas, import.meta.url);
      if (re.test(url.href) && existsSync(jalur)) return route.fulfill({ contentType: tipe, body: readFileSync(jalur) });
    }
    if (/\/webfonts\/fa-[\w-]+\.woff2$/.test(url.pathname)) {
      const jalur = new URL('./node_modules/@fortawesome/fontawesome-free/webfonts/' + url.pathname.split('/').pop(), import.meta.url);
      if (existsSync(jalur)) return route.fulfill({ contentType: 'font/woff2', body: readFileSync(jalur) });
    }
    return route.abort();
  });
}
async function konteks(browser, user, lebar = 1280) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 } });
  await siapkan(context);
  const page = await context.newPage();
  page.on('pageerror', (e) => galat.push(`${user} ${lebar}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) galat.push(`${user} ${lebar}: ${m.text()}`); });
  page.on('dialog', async (d) => { bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  for (const lebar of LEBAR) {
    const {context,page}=await konteks(browser,'sbx_admin',lebar);
    await page.goto(BASE+'/portal/v3_laporan.php');
    if(lebar===375) await page.locator('details[data-ah-filter] > summary').click();
    await page.locator('details.ah-filter__more').evaluate(el=>el.open=true);
    for(const id of ['kelas_id','kamar_id','pembimbing_id','murobi_id']) {
      const input=page.locator(`[data-ah-option-filter="${id}"]`);
      const exists=await input.count();
      cek(exists===1,`V-6 ${id} menyediakan pencarian ${lebar}px`);
      if(!exists) continue;
      await input.fill('tidak-ada-nama-audit');
      const filtered=await page.locator(`#${id} option:not([value=""])`).evaluateAll(os=>os.every(o=>o.hidden));
      cek(filtered,`V-6 ${id} menyaring pilihan tanpa menghilangkan pilihan Semua ${lebar}px`);
      await input.fill('');
      cek(await page.locator(`#${id} option:not([value=""])`).evaluateAll(os=>os.every(o=>!o.hidden)),`V-6 ${id} membersihkan pencarian ${lebar}px`);
    }
    cek(await melebar(page)<=0,`V-6 pencarian tanpa overflow ${lebar}px`);
    await page.goto(BASE+'/portal/v3_publikasi_kelola.php');
    const links=await page.locator('main tbody tr td:nth-child(3)').evaluateAll(cells=>cells.map(td=>td.querySelector('a')?.getAttribute('href')||''));
    cek(links.length>0&&links.every(Boolean),`V-5 setiap sumber bertaut ${lebar}px`);
    for(const href of [...new Set(links.filter(Boolean))]) {
      const r=await page.goto(new URL(href,BASE).href);
      cek(r.status()===200,`V-5 tautan sumber terbuka sesuai cakupan ${lebar}px`);
    }
    await context.close();
  }
  const context=await browser.newContext({javaScriptEnabled:false,viewport:{width:375,height:812}});
  await siapkan(context);
  const page=await context.newPage();
  await page.goto(BASE+'/portal/index.php');await page.fill('#username','sbx_admin');await page.fill('#password',SANDI);
  await Promise.all([page.waitForURL(/portal\/index\.php/),page.getByRole('button',{name:'Masuk',exact:true}).click()]);
  await page.goto(BASE+'/portal/v3_laporan.php');
  cek(await page.locator('#kelas_id').isVisible()&&await page.locator('[data-ah-option-filter="kelas_id"]').isHidden(),'V-6 tanpa JS: pilihan bernama tetap tersedia, pencarian tambahan tersembunyi');
  await context.close();
  cek(galat.length===0,'Probe tanpa galat JavaScript',galat.join('; '));
} finally {await browser.close();}
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);process.exitCode=gagal?1:0;
