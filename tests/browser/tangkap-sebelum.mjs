/**
 * Tangkapan layar "SEBELUM" untuk perbandingan paket perapihan V1–V2.
 *
 * Dijalankan terhadap SALINAN BASELINE (commit `c65390d`) yang dilayani pada
 * port terpisah, sehingga hasilnya dapat disandingkan dengan tangkapan layar
 * "sesudah" dari `uji-perapihan.mjs`.
 *
 * Skrip ini TIDAK menilai apa pun — ia hanya merekam keadaan sebelum, sebagai
 * lampiran audit. Data uji sintetis dari sandbox `*_test`, tanpa data pribadi.
 *
 * Jalankan:
 *   git clone <repo> /tmp/baseline && (cd /tmp/baseline && git checkout c65390d)
 *   cp .env /tmp/baseline/.env
 *   php -S 127.0.0.1:8941 -t /tmp/baseline &
 *   BASE_URL=http://127.0.0.1:8941 node tests/browser/tangkap-sebelum.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8941';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-sebelum', import.meta.url).pathname;
const AKUN = {
  username: process.env.UJI_ADMIN ?? 'sbx_admin',
  password: process.env.UJI_SANDI ?? 'Sandbox#123',
};
mkdirSync(OUT, { recursive: true });

const CDN_LOKAL = [
  { cocok: /bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, berkas: new URL('./node_modules/bootstrap/dist/css/bootstrap.min.css', import.meta.url).pathname, tipe: 'text/css' },
  { cocok: /bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, berkas: new URL('./node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', import.meta.url).pathname, tipe: 'application/javascript' },
  { cocok: /font-awesome[^"]*all\.min\.css/, berkas: new URL('./node_modules/@fortawesome/fontawesome-free/css/all.min.css', import.meta.url).pathname, tipe: 'text/css' },
];
const cdnTersedia = CDN_LOKAL.every((item) => existsSync(item.berkas));

async function pasangCdnLokal(context) {
  if (!cdnTersedia) return;
  await context.route('**/*', async (route) => {
    const url = route.request().url();
    if (url.startsWith('http://127.0.0.1') || url.startsWith('http://localhost')) {
      return route.continue();
    }
    const item = CDN_LOKAL.find((kandidat) => kandidat.cocok.test(url));
    if (item) {
      return route.fulfill({ status: 200, contentType: item.tipe, body: readFileSync(item.berkas) });
    }
    return route.abort();
  });
}

const HALAMAN = [
  { label: 'jadwal', url: '/admin/admin_jadwal_ngaji.php' },
  { label: 'pertemuan', url: '/admin/pertemuan_pengajian.php' },
  { label: 'laporan-absensi', url: '/admin/admin_laporan_absensi.php' },
  { label: 'akun', url: '/admin/admin_akun.php' },
  { label: 'akun-perizinan', url: '/admin/admin_akun_perizinan.php' },
  { label: 'guru', url: '/admin/admin_guru.php' },
  { label: 'santri-form', url: '/admin/admin_master_santri.php?action=create' },
  { label: 'portal-perizinan', url: '/portal/index.php' },
];

const LEBAR = [
  { nama: 'desktop', viewport: { width: 1440, height: 900 } },
  { nama: 'ponsel', viewport: { width: 390, height: 844 } },
];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
let nomor = 0;

try {
  for (const layar of LEBAR) {
    const context = await browser.newContext({ viewport: layar.viewport });
    await pasangCdnLokal(context);
    const page = await context.newPage();

    // Halaman masuk lama.
    await page.goto(`${BASE}/admin/admin_login.php`, { waitUntil: 'domcontentloaded' });
    nomor += 1;
    await page.screenshot({ path: `${OUT}/${String(nomor).padStart(2, '0')}-${layar.nama}-masuk-lama.jpg`, fullPage: true, type: 'jpeg', quality: 72 });

    // Masuk memakai formulir lama.
    await page.fill('#username', AKUN.username);
    await page.fill('#password', AKUN.password);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    nomor += 1;
    await page.screenshot({ path: `${OUT}/${String(nomor).padStart(2, '0')}-${layar.nama}-tujuan-setelah-masuk.jpg`, fullPage: true, type: 'jpeg', quality: 72 });

    for (const halaman of HALAMAN) {
      await page.goto(BASE + halaman.url, { waitUntil: 'domcontentloaded' });
      nomor += 1;
      await page.screenshot({
        path: `${OUT}/${String(nomor).padStart(2, '0')}-${layar.nama}-${halaman.label}.jpg`,
        fullPage: true, type: 'jpeg', quality: 72,
      });
      const melebar = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      console.log(`[rekam] ${layar.nama} ${halaman.label} — melebar: ${melebar ? 'YA' : 'tidak'}`);
    }

    await context.close();
  }
} finally {
  await browser.close();
}

console.log('');
console.log(`Tangkapan layar keadaan SEBELUM tersimpan di: ${OUT}`);
