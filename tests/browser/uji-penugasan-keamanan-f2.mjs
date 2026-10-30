/**
 * Uji browser Fase 2 penugasan lintas guru/pengurus — pemisahan tugas per akun,
 * pada 375px, 768px, dan 1280px, dengan dan tanpa JavaScript.
 *
 * Melengkapi uji server `tests/penugasan_keamanan_f2_http.php`: tampilan hanya
 * cermin, server tetap menolak. Akun X (`sbx_guru_biasa`) memegang pembimbing,
 * murobi, Bagian Pendidikan, dan Guru Mapel atas santri yang sama.
 *
 *   B-A  rincian izin pengajuan X (mode murobi): penjelasan, tanpa dialog Putuskan;
 *   B-B  panel sikap murobi X: penjelasan, isian hanya-baca, tanpa Tandai diketahui;
 *   B-C  Kelengkapan (peran Pendidikan): nilai isian X dijelaskan, tanpa Verifikasi;
 *   B-D  rincian pelanggaran dan kasus catatan X: tanpa Tandai mengetahui;
 *   B-E  admin pembuat pengajuan: tanpa Putuskan pengganti;
 *   B-F  tanpa JavaScript (375px): penjelasan tetap tampil; tanpa galat JavaScript.
 *
 *   PKF2_DB=test_pk_f2_browser_test bash tests/penugasan_keamanan_f2_db.sh
 *   PKF2_DB=test_pk_f2_browser_test php tests/penugasan_keamanan_f2_seed.php > /tmp/pkf2-ids.json
 *   DB_NAME=test_pk_f2_browser_test php -S 127.0.0.1:8979 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8979 SEED_JSON=/tmp/pkf2-ids.json node tests/browser/uji-penugasan-keamanan-f2.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8979';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-penugasan-keamanan-f2', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const IDS = JSON.parse(readFileSync(process.env.SEED_JSON ?? '/tmp/pkf2-ids.json', 'utf8'));
const LEBAR = [375, 768, 1280];
mkdirSync(OUT, { recursive: true });
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
async function konteks(browser, user, lebar = 1280, js = true) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 }, javaScriptEnabled: js });
  await siapkan(context);
  const page = await context.newPage();
  page.on('pageerror', (e) => galat.push(`${user} ${lebar}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) galat.push(`${user} ${lebar}: ${m.text()}`); });
  page.on('dialog', async (d) => { bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.locator('button[type="submit"]', { hasText: 'Masuk' }).first().click()]);
  return { context, page };
}
async function peran(page, nama) {
  await page.goto(BASE + '/portal/index.php');
  const form = page.locator('form[action$="/portal/peran.php"]').filter({ has: page.locator(`input[name="peran"][value="${nama}"]`) }).first();
  if (await form.count() === 0) return false;
  // Klik tombol kirim (bukan skrip), sehingga juga berjalan tanpa JavaScript; pil peran dapat tersembunyi di menu.
  await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"], button:not([type])').first().dispatchEvent('click')]);
  return true;
}
async function periksaLayar(page, nama, lebar) {
  const masalah = [];
  const lebih = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  const penjelasan = page.locator('[data-pemisahan-tugas], .ah-note', { hasText: /pemisahan tugas|sebagai pembimbing|isian Anda sendiri|mengajukan atau meninjau/ }).first();
  if (!(await penjelasan.isVisible())) masalah.push('penjelasan pemisahan tugas tidak terlihat');
  cek(masalah.length === 0, `${nama} ${lebar}px: penjelasan terlihat, rapi tanpa geser horizontal`, masalah.join('; '));
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_guru_biasa', lebar);
    // ------------------------------------------------------------- B-A izin murobi
    await peran(page, 'guru');
    await page.goto(`${BASE}/portal/izin_detail.php?id=${IDS.izin_x}&mode=murobi`);
    cek((await page.locator('#aksi-putuskan').count()) === 0 && (await page.locator('[data-pemisahan-tugas]').count()) === 1,
      `B-A ${lebar}px: rincian izin pengajuan X tanpa dialog Putuskan`);
    await periksaLayar(page, 'B-A rincian izin', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/izin-pengaju-375.png`, fullPage: true });
    // ------------------------------------------------------------- B-B sikap murobi
    await page.goto(`${BASE}/portal/sikap_semester.php?mode=murobi&tahun_ajaran_id=${IDS.tahun}&rencana_id=${IDS.rencana}&santri_id=${IDS.santri_kelas}`);
    cek((await page.locator('button[name="action"][value="diketahui"]').count()) === 0 && (await page.locator('#panel-sikap').count()) === 0
      && (await page.locator(`input[name="pilih[]"][value^="${IDS.rencana}:${IDS.santri_kelas}:"]`).count()) === 0,
      `B-B ${lebar}px: panel sikap murobi X hanya-baca, tanpa Tandai diketahui dan tanpa centang`);
    await periksaLayar(page, 'B-B panel sikap', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/sikap-pengisi-375.png`, fullPage: true });
    // ------------------------------------------------------------- B-D V3
    await page.goto(`${BASE}/portal/v3_pelanggaran_detail.php?id=${IDS.pelanggaran}`);
    cek((await page.locator('#aksi-ketahui').count()) === 0, `B-D ${lebar}px: pelanggaran catatan X tanpa Tandai mengetahui`);
    await periksaLayar(page, 'B-D pelanggaran', lebar);
    await page.goto(`${BASE}/portal/v3_konseling_detail.php?id=${IDS.kasus}&tab=murobi`);
    cek((await page.locator('#ketahui-kasus').count()) === 0, `B-D ${lebar}px: kasus milik X tanpa Tandai mengetahui kasus`);
    // ------------------------------------------------------------- B-C verifikasi
    cek(await peran(page, 'pendidikan'), `B-C ${lebar}px: akun X dapat beralih ke peran Pendidikan`);
    await page.goto(`${BASE}/portal/verifikasi_semester.php?rencana_id=${IDS.rencana}&santri_id=${IDS.santri_kelas}&bahan=nilai:${IDS.rm_x}`);
    cek((await page.locator('button[name="action"][value="verifikasi"]').count()) === 0 && (await page.locator('button[name="action"][value="kembalikan"]').count()) === 0,
      `B-C ${lebar}px: nilai isian X tanpa tombol Verifikasi/Kembalikan`);
    await periksaLayar(page, 'B-C kelengkapan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/verifikasi-isian-sendiri-375.png`, fullPage: true });
    await context.close();

    // ------------------------------------------------------------- B-E admin pembuat
    const admin = await konteks(browser, 'sbx_admin', lebar);
    await admin.page.goto(`${BASE}/portal/izin_detail.php?id=${IDS.izin_admin}&mode=admin`);
    cek((await admin.page.locator('#aksi-putuskan').count()) === 0 && (await admin.page.locator('#aksi-tetapkan, details[id^="aksi-"]').count()) >= 1,
      `B-E ${lebar}px: admin pembuat tanpa Putuskan pengganti; tindakan lain tetap ada`);
    await periksaLayar(admin.page, 'B-E rincian admin', lebar);
    await admin.context.close();
  }

  // ------------------------------------------------------------- B-F tanpa JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_guru_biasa', 375, false);
    cek(await peran(page, 'guru'), 'B-F 375px tanpa JavaScript: beralih ke peran Guru');
    await page.goto(`${BASE}/portal/izin_detail.php?id=${IDS.izin_x}&mode=murobi`);
    cek((await page.locator('[data-pemisahan-tugas]').isVisible()) && (await page.locator('#aksi-putuskan').count()) === 0, 'B-F 375px tanpa JavaScript: penjelasan izin tampil, tanpa Putuskan');
    await page.goto(`${BASE}/portal/sikap_semester.php?mode=murobi&tahun_ajaran_id=${IDS.tahun}&rencana_id=${IDS.rencana}&santri_id=${IDS.santri_kelas}`);
    cek((await page.locator('main').innerText()).includes('Anda mengisi sikap ini sebagai pembimbing'), 'B-F 375px tanpa JavaScript: penjelasan sikap tampil');
    await page.screenshot({ path: `${OUT}/tanpa-js-sikap-375.png`, fullPage: true });
    await context.close();
  }

  cek(galat.length === 0, 'Tanpa galat JavaScript', galat.join(' | '));
  cek(bawaan.length === 0, 'Tanpa confirm()/alert() bawaan', bawaan.join(' | '));
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal.`);
process.exit(gagal === 0 ? 0 : 1);
