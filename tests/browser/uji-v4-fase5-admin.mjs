import fs from 'node:fs';
import crypto from 'node:crypto';
import { chromium } from 'playwright';
const base = process.env.V4_TEST_URL, mobile = process.env.V4_APP_URL;
if (![base, mobile].every(url => /^http:\/\/127\.0\.0\.1:/.test(url))) throw Error('Hanya server uji localhost');
const tokens = JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS));
const ids = JSON.parse(fs.readFileSync(process.env.V4_IDS_FILE));
const out = process.env.V4_EVIDENCE_DIR;
const ok = (v, label) => { if (!v) throw Error(label); console.log('[lulus] '+label); };
async function api(user, path, body) {
 const response = await fetch(base+'/api/v1/v4/'+path, { method: body ? 'POST' : 'GET', headers: { Authorization: 'Bearer '+tokens[user], 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
 const payload = await response.json(); return { status: response.status, data: payload.data };
}
const browser = await chromium.launch({ headless: true });
async function app(user) {
 const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
 // Transport proxy for Expo web only. Backend still authenticates every real request.
 await page.route('**/api/v1/**', async route => {
  if (new URL(route.request().url()).origin !== base) { await route.abort(); throw Error('Alamat API bundle bukan localhost uji; request diblokir'); }
  if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': mobile, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
  const response = await route.fetch(); await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': mobile } });
 });
 await page.goto(mobile+'/login');
 await page.getByPlaceholder('Masukkan username').fill(user); await page.getByPlaceholder('Masukkan password').fill(process.env.V4_TEST_PASSWORD);
 await page.getByRole('button', { name: 'Masuk', exact: true }).click();
 await page.waitForURL(url => url.pathname !== '/login');
 return page;
}
async function pick(page, label, option) {
 await page.getByRole('button', { name: label, exact: true }).click();
 await page.getByRole('button', { name: option, exact: true }).click();
 await page.getByRole('button', { name: 'Tutup pilihan', exact: true }).waitFor({ state: 'hidden' });
}
async function layout(page, label) {
 for (const width of [375, 1440]) {
  await page.setViewportSize({ width, height: 900 });
  ok(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), label+' '+width+' px tanpa overflow');
  await page.screenshot({ path: `${out}/app-${label}-${width}.png`, fullPage: true });
 }
 await page.setViewportSize({width:375,height:812});
}
async function web(user, path) {
 const page = await browser.newPage({ viewport: {width:375,height:812} });
 await page.goto(base+'/portal/index.php'); await page.locator('[name=username]').fill(user); await page.locator('[name=password]').fill(process.env.V4_TEST_PASSWORD); await page.locator('button[type=submit]').first().click(); await page.goto(base+path); return page;
}
try {
 const p=await app('admin');await p.goto(mobile+'/rapor/'+ids.rapor);
 await p.getByText('Informasi tidak dapat diakses.',{exact:false}).waitFor();ok(true,'Admin tanpa relasi wali ditolak 403 pada layar aplikasi wali');
 const token=await p.evaluate(()=>sessionStorage.getItem('alhasan_teacher_api_token'));
 const apiReply=await p.request.get(base+'/api/v1/v4/rapor/'+ids.rapor,{headers:{Authorization:'Bearer '+token}});ok(apiReply.status()===403,'Admin tidak menyamar sebagai wali pada API');
 const site=await web('admin','/portal/rapor_wali.php?id='+ids.rapor);
 ok((await site.goto(base+'/portal/rapor_wali.php?id='+ids.rapor)).status()===403,'Admin tanpa relasi wali ditolak web wali');
} finally {await browser.close();}
