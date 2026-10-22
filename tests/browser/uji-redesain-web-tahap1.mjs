/**
 * Uji browser redesain website Tahap 1 — fondasi kerangka & komponen (K-1 s.d. K-4).
 *
 * Kriteria selesai Tahap 1 (cetak biru, "Urutan tahap pengerjaan"): halaman
 * yang sudah memakai Layout tampil benar di 375px, 768px, dan 1280px tanpa
 * geser horizontal halaman. Skrip ini:
 *
 *   T1-A  masuk sebagai setiap peran sandbox, membuka SETIAP menu sidebar
 *         akun itu (plus beberapa formulir ?action=create) di tiga lebar, dan
 *         memeriksa: tanpa geser horizontal, satu <h1>, tanpa galat JS, serta
 *         bentuk navigasi yang benar per ukuran (bilah bawah / rel ikon /
 *         sidebar);
 *   T1-B  laci menu HP (fokus, perangkap Tab, Esc, Keluar di laci);
 *   T1-C  grup sidebar dilipat dan diingat per peramban;
 *   T1-D  menu ⋯ (urutan, warna bahaya, posisi, Esc);
 *   T1-E  dialog konfirmasi (alasan wajib, batal tanpa permintaan POST);
 *   T1-F  toast + pesan tetap di halaman;
 *   T1-G  panel samping di tiga ukuran, galat isian, Esc;
 *   T1-H  bilah pilihan massal dan proses massal satu per satu;
 *   T1-I  kamus status;
 *   T1-J  tanpa JavaScript: panel sebagai halaman biasa, menu ⋯ tetap terbuka;
 *   T1-K  media cetak membuang kerangka.
 *
 * Galeri komponen hanya ada di router uji (tests/redesain_web_tahap1_router.php).
 * Hanya untuk basis data sandbox `*_test` dengan akun sintetis `sbx_*`.
 *
 *   php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8931 node tests/browser/uji-redesain-web-tahap1.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8931';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap1', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const GALERI = '/__uji/komponen.php';
const LEBAR = [375, 768, 1280];
const PERAN = (process.env.UJI_PERAN ?? 'sbx_admin,sbx_guru_biasa,sbx_murobi_a,sbx_pengurus_a,sbx_ortu_a').split(',');
// Formulir yang dibuka lewat alamatnya sendiri (calon panel samping Tahap 3+).
const TAMBAHAN_ADMIN = [
  '/admin/admin_guru.php?action=create',
  '/admin/admin_pengurus.php?action=create',
  '/admin/admin_kelas.php?action=create',
  '/admin/admin_pengajian.php?tab=jadwal',
  GALERI,
];
mkdirSync(OUT, { recursive: true });
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('Uji hanya untuk server lokal 127.0.0.1');

let lulus = 0;
let gagal = 0;
const galat = [];
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
  // Aset CDN dilayani dari salinan lokal agar hasil tidak bergantung jaringan.
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

function pantau(page, label) {
  page.on('pageerror', (e) => galat.push(`${label}: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) galat.push(`${label}: ${m.text()}`);
  });
}

async function masuk(page, username) {
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', username);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL('**/portal/index.php'), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
}

const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const terlihat = (page, sel) => page.locator(sel).first().evaluate((n) => {
  const s = getComputedStyle(n);
  return s.display !== 'none' && s.visibility !== 'hidden' && n.getClientRects().length > 0;
}).catch(() => false);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ---------------------------------------------------------------- T1-A
  for (const akun of PERAN) {
    const context = await browser.newContext();
    await siapkan(context);
    const page = await context.newPage();
    pantau(page, akun);
    await masuk(page, akun);
    await page.setViewportSize({ width: 1280, height: 900 });
    const menu = await page.locator('#ah-sidebar .ah-nav-sec a[href]').evaluateAll((as) => as.map((a) => new URL(a.href).pathname + new URL(a.href).search));
    const alamat = [...new Set(menu.concat(akun === 'sbx_admin' ? TAMBAHAN_ADMIN : []))];
    const bukanLayout = new Set();
    const bukan200 = new Set();
    cek(alamat.length > 1, `T1-A ${akun}: menu sidebar terbaca (${alamat.length} alamat)`);
    for (const lebar of LEBAR) {
      await page.setViewportSize({ width: lebar, height: lebar < 576 ? 812 : 1000 });
      const masalah = [];
      for (const url of alamat) {
        const galatSebelum = galat.length;
        const respon = await page.goto(BASE + url, { waitUntil: 'load' });
        const status = respon ? respon.status() : 0;
        // Status bukan 200 (data sandbox belum lengkap, cakupan ditolak) dicatat
        // terpisah dan dibandingkan dengan baseline; tata letaknya tetap diuji
        // bila halaman itu dirender di dalam kerangka.
        if (status !== 200) bukan200.add(`${url} HTTP ${status}`);
        // Halaman lama tanpa Layout (sidebar.php, ganti password) di luar
        // kriteria Tahap 1; dipindah ke kerangka baru pada Tahap 2–3.
        // Galat JS halaman lama itu (mis. Chart/jQuery dari CDN yang diblokir
        // di sandbox) juga di luar cakupan.
        if (!(await page.locator('#ah-shell').count())) { bukanLayout.add(url); galat.length = galatSebelum; continue; }
        const lebih = await melebar(page);
        if (lebih > 0) masalah.push(`${url} melebar ${lebih}px`);
        const h1 = await page.locator('main h1').count();
        if (h1 !== 1) masalah.push(`${url} h1=${h1}`);
        const bawah = await terlihat(page, '.ah-bottombar');
        const rel = await terlihat(page, '.ah-rail');
        const samping = await page.locator('#ah-sidebar').evaluate((n) => n.getBoundingClientRect().left >= 0 && getComputedStyle(n).position !== 'fixed');
        const harap = lebar < 576 ? [true, false, false] : lebar < 992 ? [false, true, false] : [false, false, true];
        if (bawah !== harap[0] || rel !== harap[1] || samping !== harap[2]) masalah.push(`${url} navigasi bawah/rel/sidebar=${bawah}/${rel}/${samping}`);
      }
      cek(masalah.length === 0, `T1-A ${akun} ${lebar}px: ${alamat.length - bukanLayout.size} halaman Layout tanpa geser horizontal, satu h1, navigasi sesuai ukuran`, masalah.slice(0, 6).join('; '));
      await page.goto(BASE + alamat[0]);
      await page.screenshot({ path: `${OUT}/a-${akun}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: false });
    }
    if (bukan200.size) console.log(`[catatan] ${akun}: status bukan 200: ${[...bukan200].join(', ')}`);
    if (bukanLayout.size) console.log(`[catatan] ${akun}: belum memakai Layout (di luar kriteria Tahap 1): ${[...bukanLayout].join(', ')}`);
    await context.close();
  }

  // Komponen diuji dengan admin.
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await siapkan(context);
  const page = await context.newPage();
  pantau(page, 'komponen');
  await masuk(page, 'sbx_admin');

  // ---------------------------------------------------------------- T1-B
  for (const lebar of [375, 768]) {
    await page.setViewportSize({ width: lebar, height: 812 });
    await page.goto(BASE + GALERI);
    cek(await page.locator('#ah-sidebar').evaluate((n) => n.inert), `T1-B ${lebar}: laci tertutup tidak masuk urutan Tab`);
    await page.locator('#ah-nav-toggle').click();
    cek(await page.locator('#ah-nav-toggle').getAttribute('aria-expanded') === 'true'
      && await page.locator('#ah-sidebar').evaluate((n) => n.contains(document.activeElement))
      && await page.locator('#ah-konten').evaluate((n) => n.inert), `T1-B ${lebar}: laci terbuka, fokus di laci, konten inert`);
    const lebarLaci = await page.locator('#ah-sidebar').evaluate((n) => n.getBoundingClientRect().width);
    cek(lebarLaci <= Math.ceil(lebar * 0.85) + 1, `T1-B ${lebar}: laci ≤ 85% layar (${Math.round(lebarLaci)}px)`);
    // Sejak Tahap 2 (A-4) Keluar berupa tombol POST ber-CSRF dengan dialog.
    cek(await page.locator('#ah-sidebar .ah-drawer-foot :is(a, button)', { hasText: 'Keluar' }).isVisible()
      && await page.locator('#ah-sidebar .ah-drawer-head').isVisible(), `T1-B ${lebar}: laci memuat nama akun dan Keluar`);
    // Laci Tahap 2 juga memuat tombol (pilih peran, Keluar) dan kotak cari.
    const links = page.locator('#ah-sidebar :is(a[href], button:not([disabled]), input:not([type="hidden"]), summary):visible');
    await links.first().focus();
    await page.keyboard.press('Shift+Tab');
    cek(await links.last().evaluate((n) => document.activeElement === n), `T1-B ${lebar}: Shift+Tab berputar di dalam laci`);
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/b-laci-${lebar}.jpg`, type: 'jpeg', quality: 70 });
    await page.keyboard.press('Escape');
    cek(await page.locator('#ah-nav-toggle').evaluate((n) => document.activeElement === n && n.getAttribute('aria-expanded') === 'false'),
      `T1-B ${lebar}: Esc menutup laci dan mengembalikan fokus`);
  }
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(BASE + GALERI);
  const butirBawah = await page.locator('.ah-bottombar a').count();
  cek(butirBawah >= 2 && butirBawah <= 5, `T1-B 375: bilah bawah berisi ${butirBawah} menu (2–5)`);
  const tinggiSentuh = await page.locator('.ah-bottombar a, .ah-topbar__btn:visible').evaluateAll((ns) => Math.min(...ns.filter((n) => n.offsetParent).map((n) => n.getBoundingClientRect().height)));
  cek(tinggiSentuh >= 44, `T1-B 375: sasaran sentuh topbar & bilah bawah ≥ 44px (${tinggiSentuh})`);
  cek(!(await terlihat(page, '.ah-profile')) && await terlihat(page, '.ah-topbar__title'), 'T1-B 375: topbar hanya ≡, judul halaman, lonceng');
  await page.setViewportSize({ width: 768, height: 900 });
  await page.goto(BASE + GALERI);
  const ikon = page.locator('.ah-rail a').first();
  await ikon.hover();
  cek(await page.locator('.ah-tip').isVisible() && (await page.locator('.ah-tip').innerText()) === 'Beranda', 'T1-B 768: rel ikon 46px menampilkan nama menu saat disorot');
  cek(Math.round(await page.locator('.ah-rail').evaluate((n) => n.getBoundingClientRect().width)) === 46, 'T1-B 768: lebar rel ikon 46px');

  // ---------------------------------------------------------------- T1-C
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(BASE + GALERI);
  const grup = page.locator('.ah-nav-sec:not([data-ah-active]):has(.ah-nav-group__btn)').first();
  const slug = await grup.getAttribute('data-ah-group');
  await grup.locator('.ah-nav-group__btn').click();
  cek(await grup.locator('.ah-nav-group__btn').getAttribute('aria-expanded') === 'false' && !(await grup.locator('.ah-nav').isVisible()), 'T1-C grup sidebar dapat dilipat');
  await page.reload();
  cek(await page.locator(`.ah-nav-sec[data-ah-group="${slug}"] .ah-nav-group__btn`).getAttribute('aria-expanded') === 'false', 'T1-C grup yang dilipat diingat setelah muat ulang');
  cek(await page.locator('.ah-nav-sec[data-ah-active] .ah-nav').isVisible(), 'T1-C grup berisi halaman aktif tetap terbuka');
  await page.locator(`.ah-nav-sec[data-ah-group="${slug}"] .ah-nav-group__btn`).click();
  cek(Math.round(await page.locator('#ah-sidebar').evaluate((n) => n.getBoundingClientRect().width)) === 240, 'T1-C sidebar desktop 240px');

  // ---------------------------------------------------------------- T1-D
  const baris = page.locator('tbody tr').first();
  const pemicu = baris.locator('.ah-menu > summary');
  await pemicu.click();
  const daftar = baris.locator('.ah-menu__list');
  const butir = await daftar.locator('.ah-menu__item').evaluateAll((ns) => ns.map((n) => ({ teks: n.innerText.trim(), bahaya: n.classList.contains('ah-menu__item--danger'), warna: getComputedStyle(n).color })));
  cek(butir.length === 3 && butir[2].bahaya && !butir[0].bahaya && butir[2].teks === 'Nonaktifkan', 'T1-D menu ⋯: tindakan berbahaya paling bawah', JSON.stringify(butir));
  cek(butir[2].warna === 'rgb(165, 34, 24)', 'T1-D menu ⋯: tindakan berbahaya berwarna merah', butir[2].warna);
  const kotak = await daftar.boundingBox();
  cek(kotak && kotak.x >= 0 && kotak.x + kotak.width <= 1280 && kotak.y + kotak.height <= 900, 'T1-D menu ⋯ tampil utuh di layar (tidak terpotong wadah tabel)');
  // Peristiwa toggle <details> dikirim asinkron; tunggu sebentar.
  cek(await pemicu.evaluate((n) => new Promise((r) => setTimeout(() => r(n.getAttribute('aria-expanded')), 50))) === 'true', 'T1-D menu ⋯ melaporkan aria-expanded');
  await page.keyboard.press('Escape');
  cek(!(await daftar.isVisible()) && await pemicu.evaluate((n) => document.activeElement === n), 'T1-D Esc menutup menu ⋯ dan fokus kembali ke pemicu');
  await page.locator('.ah-page-head__actions .ah-menu > summary').click();
  await page.locator('body').click({ position: { x: 600, y: 20 } });
  cek(!(await page.locator('.ah-page-head__actions .ah-menu__list').isVisible()), 'T1-D klik di luar menutup menu ⋯');

  // ---------------------------------------------------------------- T1-E + T1-F
  let post = 0;
  const hitung = (req) => { if (req.method() === 'POST') post++; };
  page.on('request', hitung);
  await pemicu.click();
  await daftar.getByRole('button', { name: 'Nonaktifkan' }).click();
  const dialog = page.locator('dialog.ah-dialog');
  cek(await dialog.isVisible() && (await dialog.locator('h2').innerText()) === 'Nonaktifkan Ust. Mahmud Fauzi?', 'T1-E dialog menyebut nama objek');
  cek(await dialog.locator('[data-ah-ok]').evaluate((n) => n.classList.contains('btn-danger') && n.textContent === 'Nonaktifkan'), 'T1-E tombol berbahaya merah dengan kata kerjanya');
  cek(await dialog.locator('textarea').evaluate((n) => document.activeElement === n), 'T1-E fokus pindah ke isian alasan');
  await dialog.locator('[data-ah-ok]').click();
  cek(await dialog.isVisible() && await dialog.locator('#ah-dialog-reason-error').isVisible() && post === 0, 'T1-E alasan wajib: dialog tetap terbuka, tidak ada POST');
  await page.screenshot({ path: `${OUT}/e-dialog-1280.jpg`, type: 'jpeg', quality: 70 });
  await dialog.locator('textarea').fill('Pindah tugas');
  await Promise.all([page.waitForURL(BASE + GALERI), dialog.locator('[data-ah-ok]').click()]);
  const catatan = page.locator('.ah-note--ok');
  cek((await catatan.innerText()).includes('nonaktifkan Ust. Mahmud Fauzi (alasan: Pindah tugas)'), 'T1-E konfirmasi mengirim formulir beserta alasannya');
  const toast = page.locator('#ah-toasts .ah-toast');
  cek(await toast.isVisible() && (await toast.innerText()).includes('Ust. Mahmud Fauzi') && await toast.getAttribute('aria-hidden') === 'true', 'T1-F toast tampil (tidak dibacakan dua kali)');
  const posToast = await toast.boundingBox();
  cek(posToast && posToast.x > 640 && posToast.y > 450, 'T1-F toast di pojok kanan bawah');
  await page.waitForTimeout(5300);
  cek(await toast.count() === 0 && await catatan.isVisible(), 'T1-F toast hilang setelah 5 detik, pesan di halaman tetap ada');

  post = 0;
  const lama = page.locator('#uji-form-lama button');
  await lama.click();
  cek(await dialog.isVisible() && (await dialog.locator('#ah-dialog-text').innerText()) === 'Data contoh akan diarsipkan.', 'T1-E data-confirm lama memakai dialog yang sama');
  await dialog.locator('[data-ah-cancel]').click();
  cek(post === 0 && !(await lama.isDisabled()) && await lama.evaluate((n) => document.activeElement === n), 'T1-E batal: tanpa POST, tombol tetap aktif, fokus kembali');
  await lama.click();
  await page.keyboard.press('Escape');
  cek(post === 0 && !(await dialog.isVisible()), 'T1-E Esc membatalkan dialog');
  page.off('request', hitung);

  // ---------------------------------------------------------------- T1-G
  const ukuranPanel = { 1280: [420, 420], 768: [530, 545], 375: [375, 375] };
  for (const lebar of [1280, 768, 375]) {
    await page.setViewportSize({ width: lebar, height: lebar < 576 ? 812 : 900 });
    await page.goto(BASE + GALERI);
    await Promise.all([page.waitForURL('**action=create'), page.locator('#uji-tambah').click()]);
    const panel = page.locator('#ah-panel');
    const kotakPanel = await panel.boundingBox();
    const [min, max] = ukuranPanel[lebar];
    cek(kotakPanel && kotakPanel.width >= min && kotakPanel.width <= max && Math.round(kotakPanel.x + kotakPanel.width) === lebar,
      `T1-G ${lebar}: panel samping di kanan selebar ${Math.round(kotakPanel?.width)}px`);
    if (lebar === 1280) {
      const kanan = await page.locator('table.ah-table').evaluate((n) => n.getBoundingClientRect().right);
      cek(kanan <= kotakPanel.x, 'T1-G 1280: daftar tetap terlihat utuh di samping panel');
    }
    const kaki = await page.locator('.ah-panel__foot').boundingBox();
    cek(kaki && kaki.y + kaki.height <= (lebar < 576 ? 812 : 900) + 1, `T1-G ${lebar}: tombol simpan terlihat menempel di bawah panel`);
    cek(await melebar(page) <= 0, `T1-G ${lebar}: panel tidak membuat halaman melebar`);
    await page.screenshot({ path: `${OUT}/g-panel-${lebar}.jpg`, type: 'jpeg', quality: 70 });
    await Promise.all([page.waitForURL(BASE + GALERI), page.keyboard.press('Escape')]);
    cek(!(await page.locator('#ah-panel').count()), `T1-G ${lebar}: Esc menutup panel ke alamat daftar`);
  }
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(BASE + GALERI + '?action=create');
  await Promise.all([page.waitForURL('**action=create'), page.locator('#uji-form-panel button[type="submit"]').click()]);
  cek(await page.locator('#ah-panel .ah-note--danger').innerText().then((t) => t.includes('Perbaiki 1 isian')), 'T1-G galat: ringkasan di atas formulir');
  cek(await page.locator('#nama').getAttribute('aria-invalid') === 'true' && await page.locator('#ah-panel .ah-field-error').isVisible(), 'T1-G galat: pesan di bawah isian, isian ditandai');
  await page.locator('#nama').fill('Ust. Baru');
  // Tahan respons agar keadaan "Menyimpan…" dapat diamati.
  await page.route('**' + GALERI, async (route) => { if (route.request().method() === 'POST') await new Promise((r) => setTimeout(r, 600)); return route.continue(); });
  const simpan = page.locator('#uji-form-panel button[type="submit"]');
  await simpan.click({ noWaitAfter: true });
  cek(await simpan.isDisabled() && (await simpan.innerText()) === 'Menyimpan…', 'T1-G saat menyimpan tombol menjadi "Menyimpan…" dan terkunci');
  await page.waitForURL(BASE + GALERI);
  await page.unroute('**' + GALERI);

  // ---------------------------------------------------------------- T1-H
  const pilih = page.locator('#uji-pilihan tbody input[data-ah-select]');
  cek(!(await page.locator('.ah-selbar').isVisible()), 'T1-H bilah pilihan tersembunyi saat tidak ada yang dipilih');
  await pilih.nth(0).check();
  await pilih.nth(4).check();
  cek(await page.locator('[data-ah-selcount]').innerText() === '2 guru dipilih' && await page.locator('[data-ah-select-all]').evaluate((n) => n.indeterminate), 'T1-H "2 guru dipilih", centang semua setengah');
  await page.locator('[data-ah-select-all]').check();
  cek(await page.locator('[data-ah-selcount]').innerText() === '5 guru dipilih', 'T1-H centang semua memilih 5');
  await page.locator('#uji-massal').click();
  const massal = page.locator('.ah-bulk');
  await massal.locator('.ah-bulk__summary').waitFor({ timeout: 10000 });
  cek(await massal.locator('.ah-bulk__count').innerText() === '5 / 5' && await massal.locator('progress').evaluate((n) => n.value === 5 && n.max === 5), 'T1-H proses massal maju satu per satu hingga "5 / 5"');
  cek(await massal.locator('.ah-bulk__summary').innerText() === '4 berhasil · 1 gagal', 'T1-H ringkasan berhasil/gagal');
  cek((await massal.locator('.ah-bulk__failed').innerText()).includes('Santri 5 — Nilai santri 5 belum lengkap.'), 'T1-H yang gagal didaftar beserta sebabnya');
  await page.screenshot({ path: `${OUT}/h-massal-1280.jpg`, type: 'jpeg', quality: 70, fullPage: true });
  await massal.getByRole('button', { name: 'Ulangi 1 yang belum berhasil' }).click();
  await page.locator('.ah-bulk .ah-bulk__summary').waitFor();
  cek(await page.locator('.ah-bulk .ah-bulk__count').innerText() === '1 / 1', 'T1-H tombol ulangi hanya memproses yang gagal');

  // ---------------------------------------------------------------- T1-I
  const nada = await page.locator('#uji-kamus .ah-badge').evaluateAll((ns) => Object.fromEntries(ns.map((n) => [n.textContent.trim(), n.className.replace('ah-badge ah-badge--', '')])));
  const harapan = { Selesai: 'ok', Disetujui: 'ok', Lunas: 'ok', Terbit: 'ok', Diajukan: 'info', Baru: 'info', Diserahkan: 'info', Menunggu: 'warn', 'Perlu Perbaikan': 'warn', 'Belum Lunas': 'warn', Ditolak: 'danger', Ditarik: 'danger', Gagal: 'danger', Draf: 'muted', Arsip: 'muted', Dibatalkan: 'muted' };
  cek(Object.entries(harapan).every(([k, v]) => nada[k] === v), 'T1-I kamus status: hijau/biru/kuning/merah/abu sesuai cetak biru', JSON.stringify(nada));

  // ---------------------------------------------------------------- T1-K
  await page.emulateMedia({ media: 'print' });
  const cetak = await page.evaluate(() => ['.ah-topbar', '#ah-sidebar', '.ah-rail', '.ah-bottombar', '#ah-toasts'].every((s) => { const n = document.querySelector(s); return !n || getComputedStyle(n).display === 'none'; }));
  cek(cetak, 'T1-K media cetak membuang topbar, sidebar, rel, bilah bawah, toast');
  await page.emulateMedia({ media: 'screen' });

  // Kartu tabel di HP.
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(BASE + GALERI);
  cek(await page.locator('#uji-pilihan tbody td').nth(2).evaluate((n) => getComputedStyle(n, '::before').content === '"Nama"' && getComputedStyle(n).display === 'flex'), 'T1-A 375: tabel bertanda ah-table--cards menjadi kartu berlabel');
  cek(await page.locator('.ah-tabs:not(.ah-tabs--module)').evaluate((n) => getComputedStyle(n).flexWrap === 'nowrap' && getComputedStyle(n).overflowX === 'auto'), 'T1-A 375: tab modul menjadi chip yang dapat digeser');
  await page.screenshot({ path: `${OUT}/a-galeri-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
  await context.close();

  // ---------------------------------------------------------------- T1-J
  const tanpaJs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  await siapkan(tanpaJs);
  const p2 = await tanpaJs.newPage();
  await p2.goto(BASE + '/portal/index.php');
  await p2.fill('#username', 'sbx_admin');
  await p2.fill('#password', SANDI);
  await Promise.all([p2.waitForURL('**/portal/index.php'), p2.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  await p2.goto(BASE + GALERI + '?action=create');
  cek(await p2.locator('#ah-panel').evaluate((n) => getComputedStyle(n).position === 'static'), 'T1-J tanpa JS: formulir tampil sebagai bagian halaman biasa');
  await Promise.all([p2.waitForURL(BASE + GALERI), p2.locator('#ah-panel .ah-panel__head a', { hasText: 'Tutup' }).click()]);
  cek(!(await p2.locator('#ah-panel').count()), 'T1-J tanpa JS: tombol Tutup kembali ke daftar');
  await p2.locator('tbody tr').first().locator('summary').click();
  cek(await p2.locator('tbody tr').first().locator('.ah-menu__list').isVisible(), 'T1-J tanpa JS: menu ⋯ tetap terbuka (details)');
  for (const lebar of [375, 768]) {
    await p2.setViewportSize({ width: lebar, height: 812 });
    await p2.goto(BASE + GALERI);
    cek(await terlihat(p2, lebar < 576 ? '.ah-bottombar' : '.ah-rail') && await melebar(p2) <= 0, `T1-J tanpa JS ${lebar}: menu tetap terjangkau tanpa geser horizontal`);
  }
  await tanpaJs.close();
} finally {
  await browser.close();
}

cek(galat.length === 0, 'Tanpa galat JavaScript di konsol', galat.slice(0, 5).join(' | '));
console.log(`\n${lulus} lulus, ${gagal} gagal. Tangkapan layar: ${OUT}`);
process.exit(gagal === 0 ? 0 : 1);
