// Uji browser redesain website Tahap 8 — situs publik & PSB (Chromium nyata).
//
//   KPSB_DIR=/private/tmp/<privat> KPSB_PORT=8981 KPSB_DB=test_redesain_t8_test bash tests/keamanan_psb_server.sh start
//   REDESAIN_T8_SEED=1 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_seed.php
//   BASE_URL=http://127.0.0.1:8981 T8_BROWSER=1 node tests/browser/uji-redesain-web-tahap8.mjs
//
// Setiap layar U-1 s.d. U-10 dan P-1 s.d. P-8 (+ pratinjau AG) pada 375, 768,
// 1280 px: tanpa geser horizontal halaman, tanpa galat konsol, tangkapan layar.
// Interaksi: menu HP, menu tarik-turun, penampil galeri, segmen jadwal,
// formulir PSB 4 langkah (validasi per langkah, ketik ulang HP, wilayah gagal
// dimuat -> ketik manual, ringkasan + Ubah), formulir PSB tanpa JavaScript,
// portal (tab bagian, dialog ganti No. HP, unggah terlalu besar ditolak di
// peramban), pembiayaan (total langsung, rekening), cetak tanpa bilah atas,
// tombol tampilkan No. HP. Permintaan ke luar 127.0.0.1 diblokir; Bootstrap
// dari node_modules, API wilayah dari fixture kecil. Data sintetis (NISN 96xxxxxxxx).
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const base = process.env.BASE_URL ?? '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || process.env.T8_BROWSER !== '1') throw Error('Butuh BASE_URL 127.0.0.1 dan T8_BROWSER=1');
const shots = new URL('./tangkapan-redesain-web-tahap8/', import.meta.url);
mkdirSync(shots, { recursive: true });
let lulus = 0;
const gagal = [];
const cek = (v, label) => { if (v) { lulus++; console.log('[lulus] ' + label); } else { gagal.push(label); console.log('[gagal] ' + label); } };
const nm = new URL('./node_modules/', import.meta.url);
const wilayah = { provinces: [{ id: '32', name: 'JAWA BARAT' }], regencies: [{ id: '3207', name: 'KABUPATEN CIAMIS' }], districts: [{ id: '3207010', name: 'CIAMIS' }], villages: [{ id: '3207010001', name: 'SINDANGRASA' }] };
const png = (pad = 0) => {
  const crc = buf => { let c = ~0; for (const b of buf) { c ^= b; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1)); } return ~c >>> 0; };
  const chunk = (type, data) => { const t = Buffer.from(type); const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const c = Buffer.alloc(4); c.writeUInt32BE(crc(Buffer.concat([t, data]))); return Buffer.concat([len, t, data, c]); };
  const w = 24, h = 16, ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.alloc((w * 3 + 1) * h); for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) raw.set([20, 120, 60], y * (w * 3 + 1) + 1 + x * 3);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0)), Buffer.alloc(pad)]);
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const konteks = async (width, opsi = {}) => {
  const context = await browser.newContext({ viewport: { width, height: 900 }, javaScriptEnabled: opsi.js !== false });
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === base) return route.continue();
    const api = url.pathname.match(/api-wilayah-indonesia\/api\/(provinces|regencies|districts|villages)/);
    if (api) return opsi.wilayahGagal ? route.abort() : route.fulfill({ contentType: 'application/json', body: JSON.stringify(wilayah[api[1]]) });
    if (/bootstrap.*\.min\.css$/.test(url.href)) return route.fulfill({ contentType: 'text/css', body: readFileSync(new URL('bootstrap/dist/css/bootstrap.min.css', nm)) });
    if (/bootstrap.*bundle.*\.js$/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: readFileSync(new URL('bootstrap/dist/js/bootstrap.bundle.min.js', nm)) });
    if (/font-awesome.*all\.min\.css$/.test(url.href)) return route.fulfill({ contentType: 'text/css', body: readFileSync(new URL('@fortawesome/fontawesome-free/css/all.min.css', nm)) });
    if (/\/webfonts\/fa-[\w-]+\.woff2$/.test(url.pathname)) return route.fulfill({ contentType: 'font/woff2', body: readFileSync(new URL('@fortawesome/fontawesome-free/webfonts/' + url.pathname.split('/').pop(), nm)) });
    return route.abort();
  });
  return context;
};
const pantau = page => {
  const galat = [];
  page.on('pageerror', e => galat.push(e.message));
  // Status 4xx dokumen utama (mis. kode rapor tidak dikenal 404) bukan galat halaman.
  page.on('console', m => { if (m.type() === 'error' && !/api-wilayah|net::ERR_FAILED/.test(m.text() + (m.location()?.url ?? '')) && m.location()?.url !== page.url()) galat.push(m.text()); });
  page.on('dialog', async d => { galat.push('dialog bawaan: ' + d.message()); await d.dismiss(); });
  return galat;
};
const tanpaGeser = page => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const foto = (page, nama) => page.screenshot({ path: new URL(nama + '.png', shots).pathname, fullPage: true });
const layar = async (page, galat, path, kode, width, cekTambahan = async () => true) => {
  const r = await page.goto(base + path);
  await page.waitForLoadState('load');
  const ok = r.status() < 400 && await tanpaGeser(page) && await cekTambahan();
  if (!ok && process.env.T8_DEBUG) console.log(r.status(), await tanpaGeser(page), await page.evaluate(() => [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(0, 5).map(e => e.tagName + '.' + e.className + ' ' + Math.round(e.getBoundingClientRect().left) + '-' + Math.round(e.getBoundingClientRect().right))));
  await foto(page, kode + '-' + width);
  cek(ok && galat.length === 0, `${width}: ${kode} ${path} tampil tanpa geser horizontal${galat.length ? ' — ' + galat.join(' | ') : ''}`);
  galat.length = 0;
};

try {
  for (const width of (process.env.T8_LEBAR ?? '375,768,1280').split(',').map(Number)) {
    const ctx = await konteks(width);
    const page = await ctx.newPage();
    const galat = pantau(page);

    // --- Situs publik U-1 s.d. U-10 ---
    await layar(page, galat, '/', 'U-1-beranda', width, async () => await page.locator('.s-quick a').count() === 4);
    if (width === 375) {
      cek(await page.locator('.s-sticky-cta').isVisible(), '375: tombol Daftar PSB menempel di bawah layar HP (U-1)');
      await page.click('[data-s-burger]');
      const buka = await page.locator('#s-nav').isVisible() && await page.getAttribute('[data-s-burger]', 'aria-expanded') === 'true';
      await page.screenshot({ path: new URL('U-1-menu-hp-375.png', shots).pathname });
      await page.keyboard.press('Escape');
      cek(buka && !(await page.locator('#s-nav').isVisible()), '375: menu ≡ membuka layar penuh dan menutup dengan Escape');
    } else if (width === 1280) {
      await page.click('.s-drop >> summary:has-text("Profil")');
      const item = page.getByRole('link', { name: 'Visi & misi' });
      cek(await item.isVisible(), '1280: menu Profil membuka 6 bagian');
      await Promise.all([page.waitForURL(/profil\.php\?bagian=visi-misi/), item.click()]);
      await page.click('.s-drop >> summary:has-text("Masuk")');
      const drop = page.locator('.s-drop[open] .s-drop__menu');
      cek(await drop.getByRole('link', { name: /Masuk Sistem/ }).isVisible() && await drop.getByRole('link', { name: /Portal Pendaftar/ }).isVisible(), '1280: "Masuk" di navbar berisi Masuk Sistem dan Portal Pendaftar (AM)');
    }
    await layar(page, galat, '/profil?bagian=struktur-yayasan', 'U-2-profil', width, async () => await page.locator('.s-tabs a').count() === 6);
    await layar(page, galat, '/pendidikan', 'U-3-pendidikan', width, async () => await page.locator('article.s-card--unit').count() === 4);
    await layar(page, galat, '/berita.php', 'U-4-kabar', width, async () => await page.locator('.s-card').count() === 12 && await page.getByText(/Halaman 1 dari/).isVisible());
    const tautanBerita = await page.locator('.s-card__title a').first().getAttribute('href');
    await layar(page, galat, tautanBerita, 'U-5-detail', width, async () => await page.getByRole('button', { name: 'Salin tautan' }).isVisible());
    await layar(page, galat, '/galeri.php', 'U-6-galeri', width, async () => await page.locator('.s-media figcaption').first().isVisible());
    await page.locator('[data-s-galeri]').first().click();
    const viewer = page.locator('.s-viewer');
    const v1 = await viewer.isVisible() && (await viewer.innerText()).includes('1 dari');
    await page.getByRole('button', { name: /Berikutnya/ }).click();
    const v2 = (await viewer.innerText()).includes('2 dari');
    if (width === 375) await page.screenshot({ path: new URL('U-6-penampil-375.png', shots).pathname });
    await page.keyboard.press('Escape');
    cek(v1 && v2 && !(await viewer.count()), `${width}: penampil galeri layar penuh, geser berikutnya, tutup Escape (U-6)`);
    await layar(page, galat, '/jadwal_ngaji.php', 'U-7-jadwal', width);
    await Promise.all([page.waitForURL(/waktu=Ba%27da\+Ashar|waktu=Ba%27da%20Ashar/), page.locator('.s-seg a', { hasText: "Ba'da Ashar" }).click()]);
    cek(await page.locator('.s-seg a[aria-current="page"]').innerText().then(t => t.includes('Ashar')) && await tanpaGeser(page), `${width}: segmen waktu jadwal (U-7)`);
    await layar(page, galat, '/download.php', 'U-8-unduhan', width, async () => await page.locator('.s-jenis').count() >= 2);
    await layar(page, galat, '/privacy', 'U-9-privasi', width);
    await layar(page, galat, '/verifikasi_rapor.php', 'U-10-cek-rapor', width, async () => await page.locator('.s-top').isVisible() && await page.locator('#kode').isVisible());
    await page.fill('#kode', 'c'.repeat(48));
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Periksa', exact: true }).click()]);
    cek(await page.getByText('Kode tidak ditemukan').isVisible() && galat.length === 0, `${width}: cek keaslian rapor berjalan dalam CSP ketat tanpa galat konsol (U-10)${galat.length ? ' — ' + galat.join(' | ') : ''}`);
    galat.length = 0;

    // --- P-1 formulir PSB bertahap (dengan JavaScript, wilayah gagal dimuat) ---
    const nisn = '96' + String(Date.now()).slice(-6) + String(width).padStart(4, '0').slice(-2);
    const hp = '0813' + nisn.slice(-8);
    const fctx = await konteks(width, { wilayahGagal: true });
    const f = await fctx.newPage();
    const fgalat = pantau(f);
    await layar(f, fgalat, '/psb.php', 'P-1-langkah1', width, async () => await f.locator('.s-langkah.is-aktif').count() === 1);
    await f.getByRole('button', { name: 'Lanjut ke Alamat' }).click();
    cek(await f.locator('.s-langkah').nth(0).isVisible() && !(await f.locator('.s-langkah').nth(1).isVisible())
      && await f.locator('#nama_lengkap').evaluate(el => !el.validity.valid), `${width}: langkah 1 tidak lanjut bila isian wajib kosong`);
    await f.fill('#nama_lengkap', 'Pendaftar T8 Browser ' + width);
    await f.fill('#nisn', nisn);
    await f.fill('#nik', '320700000000' + String(width).padStart(4, '0'));
    await f.fill('#tempat_lahir', 'Ciamis');
    await f.fill('#inputTanggal', '2013-02-03');
    await f.check('input[name=jenis_kelamin][value=P]');
    cek(await f.locator('option[value="MI Daar el-Qolam"]').isDisabled() && await f.locator('option[value="RA Terpadu Al Hasan"]').isDisabled(), `${width}: RA dan MI tampil tetapi belum dapat dipilih`);
    await f.selectOption('#jenjang_tujuan', 'SMK Terpadu Al Hasan');
    await f.fill('#sekolah_asal', 'SMP T8');
    await f.getByRole('button', { name: 'Lanjut ke Alamat' }).click();
    await f.getByText('Daftar wilayah gagal dimuat').waitFor();
    await f.screenshot({ path: new URL(`P-1-wilayah-gagal-${width}.png`, shots).pathname });
    await f.getByRole('button', { name: 'Ketik manual' }).click();
    cek(await f.locator('#nama_provinsi').isVisible() && await f.locator('[data-s-wilayah-ulang]').count() === 1, `${width}: wilayah gagal dimuat -> Coba lagi / Ketik manual`);
    await f.fill('#nama_provinsi', 'JAWA BARAT');
    await f.fill('#nama_kabupaten', 'KABUPATEN CIAMIS');
    await f.fill('#nama_kecamatan', 'CIAMIS');
    await f.fill('#nama_desa', 'SINDANGRASA');
    await f.fill('#alamat_jalan', 'Jl. Uji T8');
    await f.getByRole('button', { name: 'Lanjut ke Orang tua' }).click();
    await f.fill('#nama_ayah', 'Ayah T8');
    await f.fill('#nama_ibu', 'Ibu T8');
    await f.fill('#no_hp_wali', hp);
    await f.fill('#no_hp_wali_ulang', hp + '9');
    await f.getByRole('button', { name: 'Lanjut ke Periksa & kirim' }).click();
    cek(await f.locator('.s-langkah').nth(2).isVisible() && await f.locator('#no_hp_wali_ulang').evaluate(el => el.validationMessage.includes('belum sama')), `${width}: No. HP wali yang diketik ulang berbeda menahan langkah`);
    await f.fill('#no_hp_wali_ulang', hp);
    await f.getByRole('button', { name: 'Lanjut ke Periksa & kirim' }).click();
    const ringkasan = await f.locator('.s-ringkas').innerText();
    await f.screenshot({ path: new URL(`P-1-periksa-${width}.png`, shots).pathname, fullPage: true });
    cek(ringkasan.includes('Pendaftar T8 Browser ' + width) && ringkasan.includes('SMK Terpadu Al Hasan') && ringkasan.includes('SINDANGRASA, CIAMIS, KABUPATEN CIAMIS, JAWA BARAT')
      && ringkasan.includes('03-02-2013') && await tanpaGeser(f), `${width}: langkah 4 merangkum semua isian`);
    await f.locator('[data-s-ke="0"]').click();
    cek(await f.locator('#nama_lengkap').isVisible(), `${width}: tombol Ubah kembali ke langkah calon santri`);
    for (const t of ['Lanjut ke Alamat', 'Lanjut ke Orang tua', 'Lanjut ke Periksa & kirim']) await f.getByRole('button', { name: t }).click();
    await f.getByRole('button', { name: 'Kirim pendaftaran' }).click();
    cek(await f.locator('#setuju_privasi').evaluate(el => !el.validity.valid) && f.url().endsWith('/psb.php'), `${width}: kirim ditahan sampai Kebijakan Privasi dicentang (AO)`);
    await f.check('#setuju_privasi');
    await Promise.all([f.waitForURL(/status=sukses/), f.getByRole('button', { name: 'Kirim pendaftaran' }).click()]);
    await layar(f, fgalat, new URL(f.url()).pathname + new URL(f.url()).search, 'P-2-berhasil', width, async () => await f.getByRole('heading', { name: 'Pendaftaran berhasil' }).isVisible()
      && await f.getByRole('button', { name: 'Salin nomor' }).isVisible() && await f.getByRole('link', { name: 'Masuk Portal Pendaftar' }).isVisible());
    await fctx.close();

    // --- P-3 masuk portal, P-4..P-7 ---
    await layar(page, galat, '/login_pendaftar.php', 'P-3-masuk', width, async () => await page.locator('#hp').getAttribute('type') === 'password');
    await page.fill('#nisn', nisn);
    await page.fill('#hp', hp);
    await page.getByRole('button', { name: 'Tampilkan' }).click();
    cek(await page.locator('#hp').getAttribute('type') === 'text', `${width}: No. HP wali dapat ditampilkan (P-3)`);
    await Promise.all([page.waitForURL(/portal_pendaftar\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
    await layar(page, galat, '/portal_pendaftar.php', 'P-4-status', width, async () => await page.locator('#status').isVisible() && !(await page.locator('#data').isVisible()));
    cek(width < 768 ? await page.locator('.s-bottombar').isVisible() && !(await page.locator('.s-portal__side').isVisible())
      : await page.locator('.s-portal__side').isVisible() && !(await page.locator('.s-bottombar').isVisible()), `${width}: menu portal ${width < 768 ? 'bilah bawah' : 'samping'}`);
    await page.locator('[data-s-bagian="data"]:visible').first().click();
    await page.locator('#data').waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
    cek(await page.locator('#data').isVisible() && !(await page.locator('#status').isVisible()), `${width}: bagian Data diri tampil sebagai tab`);
    await foto(page, 'P-5-data-' + width);
    await page.locator('#judul-ortu').click();
    await page.fill('#u-hp', '081299990000');
    await page.getByRole('button', { name: 'Simpan data orang tua' }).click();
    const dialog = page.locator('dialog.s-dialog');
    const adaDialog = await dialog.isVisible() && (await dialog.innerText()).includes('Nomor baru akan dipakai untuk masuk');
    await page.screenshot({ path: new URL(`P-5-dialog-hp-${width}.png`, shots).pathname });
    await dialog.getByRole('button', { name: 'Batal' }).click();
    cek(adaDialog && page.url().includes('portal_pendaftar.php') && !(await dialog.count()), `${width}: ganti No. HP wali meminta konfirmasi (P-5)`);
    await page.goto(base + '/portal_pendaftar.php#berkas');
    await page.setInputFiles('input[name=file_akta]', { name: 'akta.png', mimeType: 'image/png', buffer: png(2 * 1024 * 1024 + 10) });
    await page.locator('#berkas-file_akta button[type=submit]').click();
    cek(await page.locator('#berkas-file_akta [data-s-unggah-pesan]').innerText().then(t => t.includes('lebih dari 2 MB')), `${width}: berkas > 2 MB ditolak di baris itu sebelum dikirim`);
    await page.setInputFiles('input[name=file_kk]', { name: 'kk.png', mimeType: 'image/png', buffer: png() });
    await Promise.all([page.waitForURL(/portal_pendaftar\.php/), page.locator('#berkas-file_kk button[type=submit]').click()]);
    await page.waitForLoadState('load');
    cek(await page.locator('#berkas').isVisible() && (await page.locator('#berkas-file_kk').innerText()).includes('Terunggah') && await tanpaGeser(page), `${width}: unggah satu dokumen tetap di bagian Berkas (P-6)`);
    await foto(page, 'P-6-berkas-' + width);
    await page.locator('.s-portal__side a, .s-bottombar a').filter({ hasText: 'Pembiayaan' }).filter({ visible: true }).first().click();
    await page.waitForURL(/biaya_psb\.php/);
    await layar(page, galat, '/biaya_psb.php', 'P-7-pembiayaan', width, async () => await page.locator('[aria-current="page"]:visible', { hasText: 'Pembiayaan' }).count() === 1);
    const sebelum = await page.locator('#txtTotalBayar').innerText();
    await page.uncheck('input[name="wajib[]"][value="Lemari"]');
    await page.check('input[name=metode_pembayaran][value=Transfer]');
    const sesudah = await page.locator('#txtTotalBayar').innerText();
    cek(sebelum !== sesudah && await page.getByText('1046-322-987').isVisible() && await page.getByRole('button', { name: /Salin nomor rekening BSI/ }).isVisible(), `${width}: total biaya langsung diperbarui; rekening + salin saat Transfer (P-7)`);
    await Promise.all([page.waitForURL(/cetak_biaya_psb\.php/), page.getByRole('button', { name: /Simpan & cetak kwitansi/ }).click()]);
    await layar(page, galat, new URL(page.url()).pathname + new URL(page.url()).search, 'P-8-kwitansi', width, async () => (await page.content()).includes(sesudah.replace('Rp ', '')));
    await page.emulateMedia({ media: 'print' });
    cek(!(await page.locator('.report-nav').isVisible()) && await page.locator('.identity h1').innerText() === 'Pesantren Al Hasan', `${width}: kwitansi tercetak tanpa bilah atas, kop X-1`);
    await page.emulateMedia({ media: 'screen' });
    const noreg = new URL(page.url()).searchParams.get('noreg');
    await layar(page, galat, '/cetak_bukti.php?noreg=' + noreg, 'P-8-bukti', width, async () => (await page.content()).includes(nisn) && await page.getByText('Tempel foto').isVisible());
    await page.goto(base + '/portal_pendaftar.php');
    await page.getByRole('button', { name: 'Keluar Portal' }).click();
    await Promise.all([page.waitForURL(/pesan=keluar/), page.getByRole('button', { name: 'Ya, keluar' }).click()]);
    cek(await page.getByText('Anda sudah keluar').isVisible(), `${width}: keluar portal lewat dialog konfirmasi`);
    await ctx.close();

    // --- P-1 tanpa JavaScript ---
    const nctx = await konteks(width, { js: false });
    const n = await nctx.newPage();
    await n.goto(base + '/psb.php');
    if (process.env.T8_DEBUG) console.log(await n.locator('fieldset.s-langkah:visible').count(), await n.locator('#nama_provinsi').isVisible(), await n.locator('#provinsi').isVisible(), await tanpaGeser(n), await n.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth, ...[...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(0, 6).map(e => e.tagName + '.' + e.className + '#' + e.id + ' ' + Math.round(e.getBoundingClientRect().left) + '-' + Math.round(e.getBoundingClientRect().right) + ' ' + (e.textContent||'').slice(0,40))]));
    cek(await n.locator('fieldset.s-langkah:visible').count() === 4 && await n.locator('#nama_provinsi').isVisible() && !(await n.locator('#provinsi').isVisible()) && await tanpaGeser(n),
      `${width}: tanpa JavaScript keempat langkah tampil, wilayah diketik`);
    const nisn2 = '95' + nisn.slice(2);
    for (const [s, v] of [['#nama_lengkap', 'Pendaftar T8 NoJS ' + width], ['#nisn', nisn2], ['#nik', '320700000001' + String(width).padStart(4, '0')], ['#tempat_lahir', 'Ciamis'], ['#inputTanggal', '2013-02-03'],
      ['#sekolah_asal', 'SD T8'], ['#nama_provinsi', 'JAWA BARAT'], ['#nama_kabupaten', 'KAB. CIAMIS'], ['#nama_kecamatan', 'CIAMIS'], ['#nama_desa', 'SINDANGRASA'], ['#alamat_jalan', 'Jl. T8'],
      ['#nama_ayah', 'Ayah'], ['#nama_ibu', 'Ibu'], ['#no_hp_wali', '0815' + nisn2.slice(-8)], ['#no_hp_wali_ulang', '0815' + nisn2.slice(-8)]]) await n.fill(s, v);
    // Tanpa JavaScript pemeriksaan "stabil" Playwright tidak berjalan (requestAnimationFrame), maka klik dipaksa.
    await n.check('input[name=jenis_kelamin][value=L]', { force: true });
    await n.selectOption('#jenjang_tujuan', 'MAN 2 Ciamis', { force: true });
    await n.check('#setuju_privasi', { force: true });
    await Promise.all([n.waitForURL(/status=sukses/), n.getByRole('button', { name: 'Kirim pendaftaran' }).click({ force: true })]);
    cek(await n.getByRole('heading', { name: 'Pendaftaran berhasil' }).isVisible() && await n.getByRole('link', { name: /Cetak bukti/ }).isVisible(), `${width}: pendaftaran tanpa JavaScript berhasil`);
    await nctx.close();

    // --- AG pratinjau berkas di panel Pendaftar admin ---
    const actx = await konteks(width);
    const admin = await actx.newPage();
    await admin.goto(base + '/portal/');
    await admin.fill('#username', 'sbx_admin');
    await admin.fill('#password', 'Sandbox#123');
    await Promise.all([admin.waitForURL('**/portal/index.php'), admin.getByRole('button', { name: 'Masuk', exact: true }).click()]);
    await admin.goto(base + '/admin/admin_data.php?cari=' + encodeURIComponent('Pendaftar T8 Browser ' + width));
    const href = await admin.locator('a[href*="admin_data.php?"][href*="id="]').first().getAttribute('href');
    const id = new URL(href, base + '/admin/').searchParams.get('id');
    await admin.goto(base + '/admin/admin_data.php?id=' + id + '&bagian=berkas');
    const gambar = admin.locator('.ah-media img').first();
    await gambar.scrollIntoViewIfNeeded();
    const termuat = await gambar.evaluate(img => img.complete && img.naturalWidth > 0);
    await admin.screenshot({ path: new URL(`AG-pratinjau-${width}.png`, shots).pathname });
    cek(termuat && (await gambar.getAttribute('src')).includes('lihat_berkas_psb.php?id=' + id) && await tanpaGeser(admin), `${width}: pratinjau berkas KK tampil di panel Pendaftar lewat jalur berpenjaga (AG)`);
    await actx.close();
  }
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal.length} gagal`);
for (const g of gagal) console.log('  - ' + g);
process.exit(gagal.length ? 1 : 0);
