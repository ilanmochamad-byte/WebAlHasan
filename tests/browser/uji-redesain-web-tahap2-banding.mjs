/**
 * Banding baseline redesain website Tahap 2 — alamat lama tetap hidup.
 *
 * Membuka setiap alamat menu dari sidebar BASELINE (sebelum Tahap 2) dan dari
 * peta menu baru, untuk lima akun sandbox, pada dua server:
 *
 *   BASELINE_URL  snapshot `git archive` commit sebelum Tahap 2;
 *   BASE_URL      branch Tahap 2.
 *
 * Membandingkan status HTTP setiap alamat (harus sama, kecuali perubahan yang
 * memang diputuskan: notifikasi terbuka untuk semua akun (AK) dan teks polos
 * 403 V4 yang kini halaman akses ditolak), lalu memeriksa geser horizontal
 * halaman Layout branch di 375/768/1280. Hanya membaca; DB sandbox `*_test`.
 *
 *   BASELINE_URL=http://127.0.0.1:8933 BASE_URL=http://127.0.0.1:8931 \
 *     node tests/browser/uji-redesain-web-tahap2-banding.mjs
 */
import { chromium } from 'playwright';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8931';
const BASELINE = process.env.BASELINE_URL ?? 'http://127.0.0.1:8933';
const AKUN = (process.env.UJI_PERAN ?? 'sbx_admin,sbx_guru_biasa,sbx_murobi_a,sbx_pengurus_a,sbx_ortu_a').split(',');
for (const u of [BASE, BASELINE]) if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(u)) throw new Error('Hanya server lokal');

const CDN = [
  [/bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'],
  [/bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'],
  [/font-awesome.*all\.min\.css/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css'],
];
async function konteks(browser, origin) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === origin) return route.continue();
    for (const [re, berkas, tipe] of CDN) {
      const jalur = new URL('./node_modules/' + berkas, import.meta.url);
      if (re.test(url.href) && existsSync(jalur)) return route.fulfill({ contentType: tipe, body: readFileSync(jalur) });
    }
    return route.abort();
  });
  const page = await context.newPage();
  return { context, page };
}
async function masuk(page, origin, user) {
  await page.goto(origin + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', 'Sandbox#123');
  await Promise.all([page.waitForURL('**/portal/index.php'), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
}
const alamatSidebar = (page) => page.locator('#ah-sidebar a[href]').evaluateAll((as) => [...new Set(as
  .map((a) => new URL(a.href)).filter((u) => !/logout\.php$/.test(u.pathname)).map((u) => u.pathname + u.search))]);

const baris = [];
let beda = 0;
let lebar = 0;
let dibandingkan = 0;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  for (const user of AKUN) {
    const lama = await konteks(browser, BASELINE);
    const baru = await konteks(browser, BASE);
    await masuk(lama.page, BASELINE, user);
    await masuk(baru.page, BASE, user);
    const urlLama = await alamatSidebar(lama.page);
    const urlBaru = await alamatSidebar(baru.page);
    const indeks = await baru.page.locator('#ah-menu-data').textContent().then((t) => JSON.parse(t).map((e) => new URL(e.url, BASE)).map((u) => u.pathname + u.search)).catch(() => []);
    const semua = [...new Set([...urlLama, ...urlBaru, ...indeks])];
    for (const url of semua) {
      const rl = await lama.page.goto(BASELINE + url);
      const sLama = rl ? rl.status() : 0;
      const teksLama = await lama.page.content();
      const rb = await baru.page.goto(BASE + url);
      const sBaru = rb ? rb.status() : 0;
      const layout = (await baru.page.locator('#ah-shell').count()) > 0;
      const melebar = [];
      if (layout) {
        for (const w of [375, 768, 1280]) {
          await baru.page.setViewportSize({ width: w, height: 900 });
          await baru.page.reload();
          const lebih = await baru.page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
          if (lebih > 0) melebar.push(`${w}:${lebih}px`);
        }
        await baru.page.setViewportSize({ width: 1280, height: 900 });
      }
      // Perubahan yang diputuskan Tahap 2, bukan regresi.
      let alasan = '';
      if (sLama !== sBaru) {
        if (url.startsWith('/portal/notifikasi.php') && sLama === 403 && sBaru === 200) alasan = 'AK: notifikasi untuk semua akun';
        else if (sLama === sBaru) alasan = '';
        else alasan = 'BEDA';
      }
      if (sLama === 403 && sBaru === 403 && /403 — Akses ditolak\./.test(teksLama)) alasan = 'A-5: teks polos 403 menjadi halaman akses ditolak';
      dibandingkan++;
      if (alasan === 'BEDA') beda++;
      if (melebar.length) lebar++;
      const sumber = [urlLama.includes(url) ? 'lama' : '', urlBaru.includes(url) || indeks.includes(url) ? 'baru' : ''].filter(Boolean).join('+');
      baris.push(`${user}\t${url}\t${sumber}\t${sLama}\t${sBaru}\t${layout ? 'Layout' : 'lama'}\t${melebar.join(',') || '-'}\t${alasan || '-'}`);
    }
    await lama.context.close();
    await baru.context.close();
  }
} finally {
  await browser.close();
}
const kepala = 'akun\talamat\tmenu\tstatus_baseline\tstatus_branch\tkerangka\tmelebar\tcatatan';
const isi = [kepala, ...baris].join('\n') + '\n';
if (process.env.OUT_FILE) writeFileSync(process.env.OUT_FILE, isi);
console.log(isi);
console.log(`${dibandingkan} alamat dibandingkan; status berbeda tanpa keputusan: ${beda}; halaman Layout melebar: ${lebar}`);
process.exit(beda === 0 && lebar === 0 ? 0 : 1);
