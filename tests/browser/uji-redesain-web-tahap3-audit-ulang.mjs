/** Probe auditor F-2: formulir alasan benar-benar dapat dipakai tanpa JS. */
import { chromium } from 'playwright';
import { existsSync, readFileSync, mkdirSync } from 'node:fs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:8951';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Hanya HTTP lokal.');
const out = process.env.OUT_DIR ?? '/tmp/redesain-t3-audit-ulang';
mkdirSync(out, { recursive: true });
let passed = 0, failed = 0;
const check = (ok, label) => {
  ok ? passed++ : failed++;
  console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}`);
};
const assets = [
  [/bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'],
  [/bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'],
  [/font-awesome.*all\.min\.css/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css'],
];
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  for (const width of [375, 768, 1280]) {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width, height: 900 } });
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === base) return route.continue();
      for (const [pattern, file, type] of assets) {
        const path = new URL('./node_modules/' + file, import.meta.url);
        if (pattern.test(url.href) && existsSync(path)) return route.fulfill({ contentType: type, body: readFileSync(path) });
      }
      if (/\/webfonts\/fa-[\w-]+\.woff2$/.test(url.pathname)) {
        const path = new URL('./node_modules/@fortawesome/fontawesome-free/webfonts/' + url.pathname.split('/').pop(), import.meta.url);
        if (existsSync(path)) return route.fulfill({ contentType: 'font/woff2', body: readFileSync(path) });
      }
      return route.abort();
    });
    const page = await context.newPage();
    await page.goto(base + '/portal/index.php');
    await page.fill('#username', 'sbx_admin');
    await page.fill('#password', process.env.UJI_SANDI ?? 'Sandbox#123');
    await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
    await page.goto(base + '/admin/admin_pembayaran_psb.php?status=Lunas');
    await page.locator('#daftar-pembayaran a.btn').first().click();
    const link = page.getByRole('link', { name: 'Batal lunas', exact: true });
    check(await link.isVisible(), `F-2 ${width}: tautan batal lunas terlihat tanpa JavaScript`);
    await link.click();
    const form = page.locator('#form-batal-lunas');
    const reason = form.locator('textarea[name="alasan"]');
    check(await form.isVisible() && await reason.isVisible() && await reason.getAttribute('required') !== null,
      `F-2 ${width}: formulir POST dengan textarea wajib tersedia`);
    check((await form.getAttribute('method')).toLowerCase() === 'post'
      && await form.locator('input[name="_csrf"]').getAttribute('value') !== '',
      `F-2 ${width}: formulir memakai POST dan CSRF`);
    let posts = 0;
    page.on('request', request => { if (request.method() === 'POST') posts++; });
    await form.getByRole('button', { name: 'Batalkan status lunas', exact: true }).click();
    check(posts === 0 && await reason.evaluate(el => !el.validity.valid),
      `F-2 ${width}: alasan kosong ditahan validasi HTML tanpa POST`);
    await reason.fill('Koreksi kuitansi sintetis untuk pemeriksaan formulir tanpa JS.');
    check(await reason.evaluate(el => el.validity.valid), `F-2 ${width}: alasan dapat diisi tanpa JavaScript`);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      `F-2 ${width}: halaman formulir tidak melebar`);
    await reason.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${out}/batal-tanpa-js-${width}.jpg`, type: 'jpeg', quality: 80 });
    // Preservasi fixture: tidak mengirim pembatalan sah. Persistensi alasan
    // diperiksa terpisah oleh probe PHP dan suite HTTP.
    await context.close();
  }
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${passed} lulus, ${failed} gagal`);
process.exitCode = failed ? 1 : 0;
