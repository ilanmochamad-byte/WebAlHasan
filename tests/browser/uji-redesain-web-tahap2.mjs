/**
 * Uji browser redesain website Tahap 2 — navigasi, peran & Beranda.
 *
 * Melengkapi uji server `tests/redesain_web_tahap2_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T2-A  Beranda setiap peran: tanpa geser horizontal, satu h1, bentuk
 *         navigasi per ukuran, isi bilah bawah, rel ikon sama dengan menu;
 *   T2-B  pencarian menu (AQ): hasil hanya menu/tab akun itu, panah + Enter
 *         membuka halaman, Esc menutup, tidak ada permintaan jaringan;
 *         tablet lewat tombol ⌕, HP lewat laci;
 *   T2-C  pemilih peran (A-2, AR) di topbar desktop dan di laci HP;
 *   T2-D  lonceng: pratinjau ≤ 5 notifikasi, Lihat semua, buka notifikasi;
 *   T2-E  keluar lewat dialog (A-4): Batal tidak keluar, Ya keluar;
 *   T2-F  halaman masuk (A-1): dua panel di desktop, satu kartu di HP,
 *         tampilkan kata sandi;
 *   T2-G  ganti kata sandi (A-3): daftar centang syarat;
 *   T2-H  kartu Beranda, tab modul menjadi chip di HP, pusat notifikasi,
 *         halaman akses ditolak — tanpa geser horizontal;
 *   T2-I  tanpa galat JavaScript.
 *
 *   bash tests/redesain_web_tahap2_db.sh
 *   DB_NAME=test_redesain_t2_test php -S 127.0.0.1:8941 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8941 node tests/browser/uji-redesain-web-tahap2.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8941';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap2', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
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
  await Promise.all([page.waitForURL(/\/(portal\/index|admin\/ubah_password)\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const terlihat = (page, sel) => page.locator(sel).first().evaluate((n) => {
  const s = getComputedStyle(n);
  return s.display !== 'none' && s.visibility !== 'hidden' && n.getClientRects().length > 0;
}).catch(() => false);
async function konteks(browser, akun, lebar = 1280) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 } });
  await siapkan(context);
  const page = await context.newPage();
  pantau(page, akun);
  if (akun) await masuk(page, akun);
  return { context, page };
}

const AKUN = {
  sbx_admin: { bar: ['Beranda', 'Perizinan', 'Santri', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_guru_biasa: { bar: ['Beranda', 'Pengajian', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_murobi_a: { bar: ['Beranda', 'Pengajian', 'Antrean', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_guru_mapel: { bar: ['Beranda', 'Nilai', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_pengurus_a: { bar: ['Beranda', 'Perizinan', 'Binaan', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_pengurus_biasa: { bar: ['Beranda', 'Perizinan', 'Notifikasi', 'Menu'], judul: /Assalamu'alaikum/ },
  sbx_pendidikan: { bar: ['Beranda', 'Rapor', 'Notifikasi', 'Menu'], judul: /Kemajuan semester/ },
  sbx_ortu_a: { bar: ['Beranda', 'Izin', 'Rapor', 'Info', 'Notifikasi'], judul: /Assalamu'alaikum/ },
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ---------------------------------------------------------------- T2-A
  for (const [akun, harap] of Object.entries(AKUN)) {
    const { context, page } = await konteks(browser, akun);
    const menu = await page.locator('#ah-sidebar .ah-nav-sec a').count();
    for (const lebar of LEBAR) {
      await page.setViewportSize({ width: lebar, height: lebar < 576 ? 812 : 1000 });
      await page.goto(BASE + '/portal/index.php');
      const masalah = [];
      const lebih = await melebar(page);
      if (lebih > 0) masalah.push(`melebar ${lebih}px`);
      if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
      if (!harap.judul.test(await page.locator('main h1').innerText())) masalah.push('judul Beranda');
      const bentuk = [await terlihat(page, '.ah-bottombar'), await terlihat(page, '.ah-rail'),
        await page.locator('#ah-sidebar').evaluate((n) => getComputedStyle(n).position !== 'fixed')];
      const benar = lebar < 576 ? [true, false, false] : lebar < 992 ? [false, true, false] : [false, false, true];
      if (bentuk.join() !== benar.join()) masalah.push(`navigasi ${bentuk.join('/')}`);
      if (lebar === 375) {
        const bar = await page.locator('.ah-bottombar a span:not(.ah-visually-hidden):not(.ah-bottombar__count)').allInnerTexts();
        if (bar.join('|') !== harap.bar.join('|')) masalah.push(`bilah bawah ${bar.join('|')}`);
      }
      if (lebar === 768) {
        const rel = await page.locator('.ah-rail a').count();
        if (rel !== menu) masalah.push(`rel ${rel} ≠ menu ${menu}`);
      }
      cek(masalah.length === 0, `T2-A ${akun} ${lebar}px: Beranda rapi, navigasi sesuai ukuran`, masalah.join('; '));
      await page.screenshot({ path: `${OUT}/beranda-${akun}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
    }
    await context.close();
  }

  // ---------------------------------------------------------------- T2-B
  {
    const { context, page } = await konteks(browser, 'sbx_admin');
    let permintaan = 0;
    page.on('request', (r) => { if (new URL(r.url()).origin === BASE && r.resourceType() === 'fetch') permintaan++; });
    await page.goto(BASE + '/portal/index.php');
    const cari = page.locator('#ah-cari-atas');
    await cari.fill('penem');
    const opsi = page.locator('#ah-cari-atas-hasil [role="option"]');
    cek(await opsi.count() >= 1 && (await opsi.first().innerText()).startsWith('Penempatan') && (await opsi.first().innerText()).includes('Data santri'), 'T2-B admin: "penem" menemukan Penempatan · Data santri');
    cek(await cari.getAttribute('aria-expanded') === 'true' && await opsi.first().getAttribute('aria-selected') === 'true', 'T2-B combobox: hasil pertama terpilih, aria-expanded');
    await page.keyboard.press('Enter');
    await page.waitForURL('**/admin/admin_penempatan_santri.php');
    cek(true, 'T2-B Enter membuka halaman Penempatan');
    await page.locator('#ah-cari-atas').fill('kelas');
    await page.keyboard.press('ArrowDown');
    const kedua = await page.locator('#ah-cari-atas-hasil [aria-selected="true"]').innerText();
    cek(/Kelas|kobong/i.test(kedua), `T2-B panah bawah memindah pilihan (${kedua.replace(/\n/g, ' · ')})`);
    await page.keyboard.press('Escape');
    cek(await page.locator('#ah-cari-atas-hasil').isHidden() && (await page.locator('#ah-cari-atas').inputValue()) === '', 'T2-B Esc menutup hasil dan mengosongkan kotak');
    await page.locator('#ah-cari-atas').fill('zzzz');
    cek((await page.locator('#ah-cari-atas-hasil').innerText()).includes('Menu tidak ditemukan.'), 'T2-B pencarian tanpa hasil menjelaskan');
    await page.locator('#ah-cari-atas').fill('nis santri ahmad');
    cek((await page.locator('#ah-cari-atas-hasil').innerText()).includes('Menu tidak ditemukan.'), 'T2-B hanya mencari menu, bukan data santri (AQ)');
    cek(permintaan === 0, 'T2-B pencarian berjalan di peramban tanpa permintaan ke server');
    await page.setViewportSize({ width: 768, height: 900 });
    await page.goto(BASE + '/portal/index.php');
    await page.locator('.ah-topbar__find').click();
    cek(await page.locator('#ah-cari-laci').evaluate((n) => document.activeElement === n), 'T2-B 768: tombol ⌕ membuka laci dengan fokus di kotak cari');
    await page.locator('#ah-cari-laci').fill('kanal');
    cek((await page.locator('#ah-cari-laci-hasil').innerText()).includes('Kanal notifikasi'), 'T2-B 768: hasil tampil di laci');
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/cari-768.jpg`, type: 'jpeg', quality: 70 });
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(BASE + '/portal/index.php');
    cek(!(await terlihat(page, '#ah-cari-atas')) && !(await terlihat(page, '.ah-topbar__find')), 'T2-B 375: topbar HP tetap ≡, judul, lonceng');
    await page.locator('.ah-bottombar a[data-ah-drawer]').click();
    cek(await page.locator('#ah-nav-toggle').getAttribute('aria-expanded') === 'true' && await page.locator('#ah-cari-laci').isVisible(), 'T2-B 375: butir Menu membuka laci berisi pencarian');
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/laci-375.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
    const guru = await konteks(browser, 'sbx_guru_biasa');
    await guru.page.locator('#ah-cari-atas').fill('penempatan');
    cek((await guru.page.locator('#ah-cari-atas-hasil').innerText()).includes('Menu tidak ditemukan.'), 'T2-B guru: menu admin tidak muncul di pencarian');
    await guru.context.close();
  }

  // ---------------------------------------------------------------- T2-C
  {
    const { context, page } = await konteks(browser, 'sbx_ganda');
    cek((await page.locator('.ah-peran__pill').innerText()).includes('Peran: Admin'), 'T2-C pil "Peran: Admin" di topbar desktop');
    await page.locator('.ah-peran__pill').click();
    const pilihan = await page.locator('.ah-peran .ah-peran__item b').allInnerTexts();
    cek(pilihan.join('|') === 'Admin|Guru', `T2-C pilihan peran: ${pilihan.join(', ')}`);
    await page.screenshot({ path: `${OUT}/peran-1280.jpg`, type: 'jpeg', quality: 70 });
    await Promise.all([page.waitForURL('**/portal/index.php'), page.locator('.ah-peran .ah-peran__item', { hasText: 'Guru' }).click()]);
    const menu = await page.locator('#ah-sidebar .ah-nav-sec a span').allInnerTexts();
    cek(menu.includes('Antrean izin') && !menu.includes('Data santri'), `T2-C setelah memilih Guru, menu menjadi menu guru (${menu.join(', ')})`);
    cek((await page.locator('.ah-peran__pill').innerText()).includes('Peran: Guru') && (await page.locator('.ah-note').first().innerText()).includes('Peran aktif: Guru'), 'T2-C pil dan pesan menyebut peran aktif');
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(BASE + '/portal/index.php');
    await page.locator('#ah-nav-toggle').click();
    await page.locator('.ah-drawer-peran > summary').click();
    cek(await page.locator('.ah-drawer-peran .ah-peran__item').count() === 2, 'T2-C 375: pemilih peran di bagian atas laci');
    await page.screenshot({ path: `${OUT}/peran-laci-375.jpg`, type: 'jpeg', quality: 70 });
    await Promise.all([page.waitForURL('**/portal/index.php'), page.locator('.ah-drawer-peran .ah-peran__item', { hasText: 'Admin' }).click()]);
    cek((await page.locator('main h1').innerText()).includes("Assalamu'alaikum") && await page.locator('[data-ah-kartu]').count() > 0, 'T2-C 375: kembali ke Admin menampilkan kartu prioritas');
    await context.close();
    const tunggal = await konteks(browser, 'sbx_murobi_a');
    cek(await tunggal.page.locator('.ah-peran').count() === 0, 'T2-C akun berperan tunggal tidak mendapat pemilih peran');
    await tunggal.context.close();
  }

  // ---------------------------------------------------------------- T2-D
  for (const lebar of [375, 1280]) {
    const { context, page } = await konteks(browser, 'sbx_ortu_a', lebar);
    await page.locator('.ah-bell > summary').click();
    const butir = await page.locator('.ah-bell .ah-bell__item').count();
    cek(butir >= 1 && butir <= 5 && await page.locator('.ah-bell a', { hasText: 'Lihat semua notifikasi' }).isVisible(), `T2-D ${lebar}: lonceng memuat ${butir} notifikasi (≤5) dan Lihat semua`);
    const kotak = await page.locator('.ah-bell .ah-menu__list').boundingBox();
    cek(kotak && kotak.x >= 0 && kotak.x + kotak.width <= lebar + 1, `T2-D ${lebar}: pratinjau lonceng tidak terpotong layar`);
    await page.screenshot({ path: `${OUT}/lonceng-${lebar}.jpg`, type: 'jpeg', quality: 70 });
    await page.locator('.ah-bell a', { hasText: 'Lihat semua notifikasi' }).click();
    await page.waitForURL('**/portal/notifikasi.php');
    cek(await melebar(page) <= 0 && await page.locator('.ah-chips a').count() === 6, `T2-D ${lebar}: pusat notifikasi dengan 6 chip tanpa geser horizontal`);
    await page.screenshot({ path: `${OUT}/notifikasi-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T2-E
  for (const lebar of [375, 1280]) {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', lebar);
    let post = 0;
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('logout.php')) post++; });
    const buka = async () => {
      if (lebar < 576) {
        await page.locator('#ah-nav-toggle').click();
        await page.locator('#ah-sidebar .ah-drawer-foot__btn').click();
      } else {
        await page.locator('.ah-profile > summary').click();
        await page.locator('.ah-profile .ah-logout button').click();
      }
    };
    await buka();
    const dialog = page.locator('dialog.ah-dialog');
    cek(await dialog.isVisible() && (await dialog.locator('.ah-dialog__title').innerText()) === 'Keluar dari Sistem Al Hasan?'
      && (await dialog.locator('[data-ah-ok]').innerText()) === 'Ya, keluar', `T2-E ${lebar}: Keluar membuka dialog`);
    await page.screenshot({ path: `${OUT}/keluar-${lebar}.jpg`, type: 'jpeg', quality: 70 });
    await dialog.locator('[data-ah-cancel]').click();
    await page.waitForTimeout(200);
    cek(post === 0 && await page.locator('#ah-shell').count() === 1, `T2-E ${lebar}: Batal tidak mengirim apa pun`);
    await page.goto(BASE + '/portal/index.php');
    await buka();
    await Promise.all([page.waitForURL('**/portal/index.php?pesan=logout'), dialog.locator('[data-ah-ok]').click()]);
    cek(post === 1 && (await page.locator('.ah-note').innerText()).includes('Anda sudah keluar'), `T2-E ${lebar}: Ya, keluar mengirim POST dan kembali ke halaman masuk`);
    await page.goto(BASE + '/portal/notifikasi.php');
    cek(page.url().includes('pesan=sesi'), `T2-E ${lebar}: sesi benar-benar berakhir`);
    await context.close();
  }

  // ---------------------------------------------------------------- T2-F
  for (const lebar of [375, 1280]) {
    const { context, page } = await konteks(browser, null, lebar);
    await page.goto(BASE + '/portal/index.php?pesan=gagal');
    cek(await terlihat(page, '.ah-masuk__id') === (lebar >= 992) && await melebar(page) <= 0, `T2-F ${lebar}: ${lebar >= 992 ? 'dua panel' : 'satu kartu'} tanpa geser horizontal`);
    cek((await page.locator('.ah-note').innerText()).includes('Nama pengguna atau kata sandi salah.'), `T2-F ${lebar}: pesan gagal ditulis ulang`);
    await page.fill('#password', 'rahasia');
    await page.locator('[data-ah-reveal]').click();
    cek(await page.locator('#password').getAttribute('type') === 'text' && await page.locator('[data-ah-reveal]').getAttribute('aria-pressed') === 'true', `T2-F ${lebar}: Tampilkan memperlihatkan kata sandi`);
    await page.locator('[data-ah-reveal]').click();
    cek(await page.locator('#password').getAttribute('type') === 'password', `T2-F ${lebar}: Sembunyikan mengembalikan`);
    await page.screenshot({ path: `${OUT}/masuk-${lebar}.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
  }

  // ---------------------------------------------------------------- T2-G
  {
    const { context, page } = await konteks(browser, 'sbx_sandi_wajib', 375);
    cek(page.url().includes('ubah_password.php') && await page.locator('#ah-sidebar').count() === 0 && await page.locator('.ah-bottombar').count() === 0, 'T2-G kata sandi wajib: kerangka polos tanpa menu');
    const ok = () => page.locator('.ah-checklist li.is-ok').count();
    cek(await ok() === 0, 'T2-G syarat belum terpenuhi saat kosong');
    await page.fill('#password_baru', 'abc');
    cek(await ok() === 1, 'T2-G huruf kecil tercentang');
    await page.fill('#password_baru', 'Abcdefghijk1');
    cek(await ok() === 4 && await melebar(page) <= 0, 'T2-G keempat syarat tercentang, tanpa geser horizontal');
    await page.screenshot({ path: `${OUT}/sandi-wajib-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T2-H
  {
    const { context, page } = await konteks(browser, 'sbx_admin');
    await page.goto(BASE + '/portal/index.php');
    const kolom = await page.locator('.ah-task-grid').evaluate((n) => getComputedStyle(n).gridTemplateColumns.split(' ').length);
    cek(kolom === 4 && await page.locator('[data-ah-kartu]').count() === 4, `T2-H 1280: "Perlu tindakan" 4 kartu dalam 4 kolom (${kolom})`);
    await page.locator('a', { hasText: 'lainnya · Lihat semua' }).click();
    cek(await page.locator('[data-ah-kartu]').count() === 8, 'T2-H "Lihat semua" menampilkan 8 kartu');
    await page.screenshot({ path: `${OUT}/beranda-admin-semua-1280.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    for (const [url, nama] of [['/admin/admin_master_santri.php', 'data-santri'], ['/portal/izin_ringkasan.php', 'perizinan'], ['/portal/v3_pelanggaran.php', 'pembinaan']]) {
      for (const lebar of [375, 1280]) {
        await page.setViewportSize({ width: lebar, height: lebar < 576 ? 812 : 900 });
        await page.goto(BASE + url);
        const tab = page.locator('.ah-tabs--module');
        const chip = await tab.locator('a').first().evaluate((n) => getComputedStyle(n).borderRadius);
        cek(await tab.count() === 1 && await tab.locator('[aria-current="page"]').count() === 1 && await melebar(page) <= 0
          && (lebar < 576 ? chip === '999px' : chip !== '999px'), `T2-H ${lebar}: tab modul ${nama} (${lebar < 576 ? 'chip' : 'tab'}), satu aktif, tanpa geser`);
        if (lebar === 375) await page.screenshot({ path: `${OUT}/tab-${nama}-375.jpg`, type: 'jpeg', quality: 70 });
      }
    }
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(BASE + '/portal/index.php');
    const kolomHp = await page.locator('.ah-task-grid').evaluate((n) => getComputedStyle(n).gridTemplateColumns.split(' ').length);
    const stat = await page.locator('.ah-beranda__stats').evaluate((n) => getComputedStyle(n).gridTemplateColumns.split(' ').length);
    cek(kolomHp === 1 && stat === 2, `T2-H 375: kartu tindakan 1 kolom, statistik 2 kolom (${kolomHp}/${stat})`);
    await context.close();
    for (const [akun, url, nama] of [['sbx_pendidikan', '/portal/index.php', 'pendidikan'], ['sbx_ortu_a', '/portal/index.php', 'ortu'], ['sbx_guru_mapel', '/admin/admin_master_santri.php', 'ditolak'], ['sbx_pengurus_a', '/portal/index.php', 'pengurus']]) {
      const k = await konteks(browser, akun, 375);
      await k.page.goto(BASE + url);
      cek(await melebar(k.page) <= 0, `T2-H 375 ${nama}: tanpa geser horizontal`);
      if (nama === 'ortu') {
        const huruf = await k.page.locator('.ah-parent-card').first().evaluate((n) => parseFloat(getComputedStyle(n).fontSize));
        cek(await k.page.locator('.ah-chips a').count() === 2 && huruf >= 16, `T2-H 375 ortu: pilihan dua anak, huruf kartu ${huruf}px`);
      }
      if (nama === 'ditolak') {
        cek(await k.page.locator('#ah-shell').count() === 1 && (await k.page.locator('main h1').innerText()) === 'Halaman ini bukan untuk peran Anda', 'T2-H akses ditolak di dalam kerangka');
      }
      if (nama === 'pengurus') {
        const tombol = await k.page.locator('.ah-page-head__actions a').boundingBox();
        cek(tombol && tombol.width >= 300, `T2-H 375 pengurus: Ajukan izin penuh lebar (${tombol && Math.round(tombol.width)}px)`);
      }
      await k.page.screenshot({ path: `${OUT}/hp-${nama}-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
      await k.context.close();
    }
  }

  // ---------------------------------------------------------------- T2-I
  cek(galat.length === 0, 'T2-I tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
