// Uji browser workstream keamanan PSB (Chromium nyata, 375/768/1280 px).
//
//   KPSB_DIR=/private/tmp/<privat> bash tests/keamanan_psb_server.sh start
//   BASE_URL=http://127.0.0.1:8971 KPSB_BROWSER=1 node tests/browser/uji-keamanan-psb.mjs
//
// Per lebar: anonim tidak melihat rincian biaya dan folder berkas ditolak;
// mendaftar lewat formulir publik lalu mencetak bukti dari peramban yang sama;
// masuk Portal Pendaftar (NISN + No. HP), unggah KTP, membuka berkas sendiri
// lewat jalur berpenjaga; simpan rincian biaya ke kwitansi; keluar lewat POST;
// admin membuka berkas pendaftar dari panel. Halaman tidak bergeser horizontal.
// Permintaan ke luar 127.0.0.1 diblokir; Bootstrap dari node_modules dan API
// wilayah dari fixture kecil; flatpickr/AOS diganti stub kosong. Data sintetis (NISN acak 98xxxxxxxx).
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const base = process.env.BASE_URL ?? '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || process.env.KPSB_BROWSER !== '1') throw Error('Butuh BASE_URL 127.0.0.1 dan KPSB_BROWSER=1');
const shots = new URL('./tangkapan-keamanan-psb/', import.meta.url);
mkdirSync(shots, { recursive: true });
let lulus = 0;
const gagal = [];
const cek = (v, label) => { if (v) { lulus++; console.log('[lulus] ' + label); } else { gagal.push(label); console.log('[gagal] ' + label); } };

// PNG 24×16 hijau, dibuat tanpa pustaka.
const png = (() => {
  const crc = buf => { let c = ~0; for (const b of buf) { c ^= b; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1)); } return ~c >>> 0; };
  const chunk = (type, data) => { const t = Buffer.from(type); const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const c = Buffer.alloc(4); c.writeUInt32BE(crc(Buffer.concat([t, data]))); return Buffer.concat([len, t, data, c]); };
  const w = 24, h = 16, ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.alloc((w * 3 + 1) * h); for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) raw.set([20, 120, 60], y * (w * 3 + 1) + 1 + x * 3);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
})();
const wilayah = { provinces: [{ id: '32', name: 'JAWA BARAT' }], regencies: [{ id: '3207', name: 'KABUPATEN CIAMIS' }], districts: [{ id: '3207010', name: 'CIAMIS' }], villages: [{ id: '3207010001', name: 'SINDANGRASA' }] };
const nm = new URL('./node_modules/', import.meta.url);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const konteks = async width => {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === base) return route.continue();
    const api = url.pathname.match(/api-wilayah-indonesia\/api\/(provinces|regencies|districts|villages)/);
    if (api) return route.fulfill({ contentType: 'application/json', body: JSON.stringify(wilayah[api[1]]) });
    if (/bootstrap.*\.min\.css$/.test(url.href)) return route.fulfill({ contentType: 'text/css', body: readFileSync(new URL('bootstrap/dist/css/bootstrap.min.css', nm)) });
    if (/bootstrap.*bundle.*\.js$/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: readFileSync(new URL('bootstrap/dist/js/bootstrap.bundle.min.js', nm)) });
    if (/font-awesome.*all\.min\.css$/.test(url.href)) return route.fulfill({ contentType: 'text/css', body: readFileSync(new URL('@fortawesome/fontawesome-free/css/all.min.css', nm)) });
    // Pustaka CDN lain diganti stub kosong (uji luring); kolom tanggal tetap teks biasa.
    if (/flatpickr/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: url.pathname.endsWith('.js') && url.pathname.includes('l10n') ? '' : 'window.flatpickr=function(){};' });
    if (/aos.*\.js$/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: 'window.AOS={init(){},refresh(){}};' });
    return route.abort();
  });
  return context;
};
const tanpaGeser = page => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

try {
  for (const width of [375, 768, 1280]) {
    const nisn = '98' + String(Date.now()).slice(-6) + String(width).padStart(4, '0').slice(-2);
    const hp = '0813' + nisn.slice(-8);
    const ctx = await konteks(width);
    const page = await ctx.newPage();
    const pesan = [];
    page.on('dialog', async d => { pesan.push(d.message()); await d.accept(); });

    // Anonim
    await page.goto(base + '/biaya_psb.php?cari=KPSB-T-0002');
    cek(await page.getByText('Rincian pembiayaan hanya dapat dibuka').isVisible() && !(await page.content()).includes('Pendaftar Uji B'), width + ': anonim tidak melihat rincian biaya');
    cek((await ctx.request.get(base + '/berkas_psb/')).status() === 403, width + ': folder berkas_psb/ ditolak');

    // Daftar
    await page.goto(base + '/psb.php');
    await page.fill('input[name=nama_lengkap]', 'Pendaftar Browser ' + width);
    await page.fill('input[name=nisn]', nisn);
    await page.fill('input[name=nik]', '320700000000' + String(width).padStart(4, '0'));
    await page.fill('input[name=tempat_lahir]', 'Ciamis');
    await page.fill('#inputTanggal', '2013-02-03');
    await page.selectOption('select[name=jenis_kelamin]', 'L');
    await page.selectOption('select[name=jenjang_tujuan]', 'SMP Terpadu Al Hasan');
    await page.waitForSelector('#provinsi option[value="32"]', { state: 'attached' });
    await page.selectOption('#provinsi', '32');
    await page.waitForSelector('#kabupaten option[value="3207"]', { state: 'attached' });
    await page.selectOption('#kabupaten', '3207');
    await page.waitForSelector('#kecamatan option[value="3207010"]', { state: 'attached' });
    await page.selectOption('#kecamatan', '3207010');
    await page.waitForSelector('#desa option[value="3207010001"]', { state: 'attached' });
    await page.selectOption('#desa', '3207010001');
    await page.fill('textarea[name=alamat_jalan]', 'Jl. Uji Browser');
    await page.fill('input[name=sekolah_asal]', 'SD Uji');
    await page.fill('input[name=nama_ayah]', 'Ayah Browser');
    await page.fill('input[name=nama_ibu]', 'Ibu Browser');
    await page.fill('input[name=no_hp_wali]', hp);
    cek(await page.locator('form[action="proses_psb.php"] input[name=_csrf]').count() === 1 && await tanpaGeser(page), width + ': formulir PSB ber-CSRF tanpa geser horizontal');
    await Promise.all([page.waitForURL(/status=sukses/), page.click('button[name=daftar]')]);
    const noreg = new URL(page.url()).searchParams.get('noreg');
    cek(/^PSB-\d{4}-\d{4}$/.test(noreg ?? '') && await page.getByText('Pendaftaran Berhasil!').isVisible(), width + ': pendaftaran tersimpan ' + noreg);
    await page.screenshot({ path: new URL(`daftar-sukses-${width}.png`, shots).pathname, fullPage: false });
    const [bukti] = await Promise.all([ctx.waitForEvent('page'), page.getByRole('link', { name: /CETAK BUKTI PENDAFTARAN/ }).click()]);
    await bukti.waitForLoadState();
    cek((await bukti.content()).includes(nisn), width + ': peramban pendaftar mencetak buktinya');
    await bukti.close();

    // Portal
    await page.goto(base + '/login_pendaftar.php');
    await page.fill('input[name=nisn]', nisn);
    await page.fill('input[name=hp]', hp);
    await Promise.all([page.waitForURL(/portal_pendaftar\.php/), page.getByRole('button', { name: 'MASUK KE PORTAL' }).click()]);
    cek(await page.getByText('Pendaftar Browser ' + width).first().isVisible() && await tanpaGeser(page), width + ': masuk portal tanpa geser horizontal');
    await page.screenshot({ path: new URL(`portal-${width}.png`, shots).pathname, fullPage: false });
    await page.click('#berkas-tab');
    await page.setInputFiles('input[name=file_ktp]', { name: 'ktp.png', mimeType: 'image/png', buffer: png });
    await Promise.all([page.waitForURL(/portal_pendaftar\.php/), page.getByRole('button', { name: /UNGGAH & SIMPAN BERKAS/ }).click()]);
    await page.waitForLoadState('load');
    cek(pesan.some(m => m.includes('Berkas berhasil diperbarui: KTP orang tua')), width + ': unggah KTP berhasil dengan pesan');
    const berkas = await ctx.request.get(base + '/lihat_berkas_psb.php?jenis=file_ktp');
    cek(berkas.status() === 200 && berkas.headers()['content-type'] === 'image/png' && (await berkas.body()).subarray(0, 4).equals(png.subarray(0, 4)), width + ': berkas sendiri terbuka lewat jalur berpenjaga');
    await page.goto(base + '/lihat_berkas_psb.php?jenis=file_ktp');
    cek(await page.locator('img').count() === 1, width + ': peramban menampilkan gambar berkas');

    // Biaya
    await page.goto(base + '/portal_pendaftar.php');
    await page.getByRole('link', { name: /Rincian & Pembayaran/ }).click();
    await page.waitForURL(/biaya_psb\.php/);
    cek(await page.getByText('Konfirmasi & Atur Pembiayaan').isVisible() && await tanpaGeser(page), width + ': rincian biaya milik sendiri tanpa geser horizontal');
    await page.uncheck('input[name="wajib[]"][value="Lemari"]');
    const harapan = (await page.locator('#txtTotalBayar').innerText()).trim();
    const [kwitansi] = await Promise.all([ctx.waitForEvent('page'), page.getByRole('button', { name: /Simpan & Cetak Kwitansi/ }).click()]);
    await kwitansi.waitForLoadState();
    const isiKwitansi = await kwitansi.content();
    cek(kwitansi.url().includes('cetak_biaya_psb.php?noreg=' + noreg) && isiKwitansi.includes(harapan.replace('Rp ', '')) && !isiKwitansi.includes('Lemari'), width + ': kwitansi sesuai pilihan (' + harapan + ')');
    await kwitansi.close();

    // Keluar
    await page.goto(base + '/portal_pendaftar.php');
    await Promise.all([page.waitForURL(/pesan=keluar/), page.getByRole('button', { name: /Keluar Portal/ }).click()]);
    cek(await page.getByText('Anda sudah keluar').isVisible() && (await ctx.request.get(base + '/lihat_berkas_psb.php?jenis=file_ktp')).status() === 401, width + ': keluar lewat POST mengakhiri akses berkas');
    await ctx.close();

    // Admin
    const actx = await konteks(width);
    const admin = await actx.newPage();
    await admin.goto(base + '/portal/');
    await admin.fill('#username', 'sbx_admin');
    await admin.fill('#password', 'Sandbox#123');
    await Promise.all([admin.waitForURL('**/portal/index.php'), admin.getByRole('button', { name: 'Masuk', exact: true }).click()]);
    await admin.goto(base + '/admin/admin_data.php?cari=' + noreg);
    const href = await admin.locator(`a[href*="id="]`).filter({ hasText: /Pendaftar Browser|Detail|Buka/ }).first().getAttribute('href')
      ?? await admin.locator('a[href*="admin_data.php?"][href*="id="]').first().getAttribute('href');
    const id = new URL(href, base + '/admin/').searchParams.get('id');
    await admin.goto(base + '/admin/admin_data.php?id=' + id + '&bagian=berkas');
    const lihat = admin.locator('a[href*="lihat_berkas_psb.php"]').first();
    cek(await lihat.isVisible() && await tanpaGeser(admin), width + ': panel admin menampilkan tautan berkas berpenjaga tanpa geser horizontal');
    await admin.screenshot({ path: new URL(`admin-berkas-${width}.png`, shots).pathname, fullPage: false });
    const [tab] = await Promise.all([actx.waitForEvent('page'), lihat.click()]);
    await tab.waitForLoadState();
    const resp = await actx.request.get(tab.url());
    cek(tab.url().includes('lihat_berkas_psb.php') && await tab.evaluate(() => document.contentType) === 'image/png'
      && resp.status() === 200 && resp.headers()['content-type'] === 'image/png', width + ': admin membuka berkas pendaftar');
    await actx.close();
  }
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal.length} gagal`);
for (const g of gagal) console.log('  - ' + g);
process.exit(gagal.length ? 1 : 0);
