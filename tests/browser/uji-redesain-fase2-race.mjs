// Audit Fase 2: respons pekan terbalik dan paginasi izin.
// API nyata localhost:8940 (DB *_test), Expo localhost:8082.
// Hanya waktu respons schedules pertama yang ditunda; muatan tidak diubah.
// node tests/browser/uji-redesain-fase2-race.mjs <folder-tangkapan>
import { chromium } from 'playwright';

const app = 'http://127.0.0.1:8082';
const api = 'http://127.0.0.1:8940';
const out = process.argv[2];
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
const hasil = [];
const cek = (nama, lulus, info = '') => hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${info ? ' — ' + info : ''}`);
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const hariIni = iso(new Date());

async function sesi(browser, user, { sisipSesiHariIni = false } = {}) {
  const context = await browser.newContext({ viewport: { width: 390, height: 900 }, timezoneId: 'Asia/Jakarta' });
  const permintaan = [];
  await context.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.origin === app) return route.continue();
    if (url.origin !== api) return route.abort();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    permintaan.push(url.pathname + url.search);
    const res = await route.fetch();
    if (sisipSesiHariIni && url.pathname.endsWith('/schedules') && url.searchParams.get('date_from') <= hariIni && url.searchParams.get('date_to') >= hariIni) {
      const json = await res.json();
      const contoh = json.data.items[0];
      if (contoh) {
        json.data.items.unshift({ ...contoh, id: 990001, occurrence_date: hariIni, start_time: '07:30', end_time: '09:00', meeting: null });
        json.data.pagination.total += 1;
      }
      return route.fulfill({ response: res, headers: { ...res.headers(), ...cors }, body: JSON.stringify(json) });
    }
    return route.fulfill({ response: res, headers: { ...res.headers(), ...cors } });
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await page.getByPlaceholder('Masukkan password').press('Enter');
  await page.getByText('Assalamu', { exact: false }).waitFor({ timeout: 15000 });
  return { context, page, errors, permintaan };
}

async function tutup(context) {
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}

const browser = await chromium.launch();


const {context,page}=await sesi(browser,'sbx_admin');
await page.goto(app+'/schedules');
await page.getByText('SESI',{exact:false}).first().waitFor();
let delayed=false;
await context.route('**/api/v1/schedules?**',async route=>{
 const url=new URL(route.request().url());
 const res=await route.fetch();
 if(!delayed){delayed=true;await new Promise(r=>setTimeout(r,2000));}
 await route.fulfill({response:res,headers:{...res.headers(),...cors}});
});
await page.getByRole('button',{name:'Pekan berikutnya'}).click();
await page.waitForTimeout(200);
await page.getByRole('button',{name:'Pekan berikutnya'}).click();
await page.waitForTimeout(3000);
const count=await page.getByText('SESI',{exact:false}).count();
console.log('RACE final week content count:',count);
await page.screenshot({path:out+'/race.png'});
await page.goto(app+'/perizinan');
await page.getByRole('button',{name:/^Pengajuan /}).first().waitFor();
const first=await page.getByRole('button',{name:/^Pengajuan /}).first().getAttribute('aria-label');
await page.getByRole('button',{name:'Berikutnya',exact:true}).click();
await page.getByText(/Halaman 2 dari/).waitFor();
const second=await page.getByRole('button',{name:/^Pengajuan /}).first().getAttribute('aria-label');
if(first===second)throw Error('Pagination did not change records');
await page.getByRole('radio',{name:'Disetujui',exact:true}).click();
await page.getByText(/Halaman 1 dari/).waitFor();
console.log('PASS pagination page 2 and filter resets to page 1');

await tutup(context);await browser.close();
process.exitCode=count?0:1;
