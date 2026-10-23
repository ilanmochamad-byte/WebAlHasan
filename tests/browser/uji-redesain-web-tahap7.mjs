/**
 * Uji browser redesain website Tahap 7 — penilaian & rapor.
 *
 * Melengkapi uji server `tests/redesain_web_tahap7_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T7-A  setiap layar R-1 s.d. R-8 dan M-5 untuk akun yang memakainya: tanpa
 *         geser halaman, satu h1, kerangka bersama, tombol utama ≥ 44 px,
 *         panel samping di desktop, tanpa isian ID;
 *   T7-B  R-2 kisi 30 santri satu layar: nilai akhir & predikat dihitung
 *         langsung, peringatan perubahan belum disimpan, Simpan draf,
 *         alasan koreksi muncul bila nilai tersimpan diubah (keputusan U);
 *   T7-C  R-2 "Serahkan yang lengkap": bilah kemajuan satu per satu (V);
 *   T7-D  R-2 di HP: satu santri per kartu, Sebelumnya/Berikutnya;
 *   T7-E  R-4 murobi: centang semua → Tandai diketahui (bilah kemajuan);
 *   T7-F  R-5 matriks: sel membuka panel; "Verifikasi semua yang diserahkan";
 *   T7-G  R-6 "Terbitkan yang siap": PDF dibuat satu per satu (V); bilah pilihan;
 *   T7-H  R-1 "Salin dari semester lalu" mengisi formulir lalu Simpan (X);
 *         guru nonaktif ditandai; Tambah mapel; pratinjau tanda tangan;
 *   T7-I  R-8 orang tua: versi terbaru saja + "Versi sebelumnya" (Y);
 *   T7-J  tanpa JavaScript: kisi tersimpan dan "Serahkan yang lengkap" diproses server;
 *   T7-K  tanpa galat JavaScript, tanpa confirm()/alert() bawaan.
 *
 *   bash tests/redesain_web_tahap7_db.sh
 *   DB_NAME=test_redesain_t7_test php -S 127.0.0.1:8977 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8977 node tests/browser/uji-redesain-web-tahap7.mjs
 *
 * Uji ini MENGUBAH data uji (nilai, sikap, verifikasi, rapor terbit). Bangun
 * ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8977';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap7', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const IDS = JSON.parse(readFileSync(new URL('../../storage/private/v4/redesain_t7_ids.json', import.meta.url)));
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
  page.on('dialog', async (d) => { if (d.type() !== 'beforeunload') bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const dialogForm = (page) => page.locator('dialog.ah-dialog--form[open]');
const teksMain = (page) => page.locator('main').innerText();
const tungguMassal = async (page) => { await page.locator('.ah-bulk__summary').waitFor({ timeout: 120000 }); return page.locator('.ah-bulk__summary').innerText(); };

async function periksaLayar(page, nama, lebar) {
  const masalah = [];
  const lebih = await melebar(page);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  if (lebar === 375) {
    for (const tabel of await page.locator('table.ah-table--cards, table.ah-kisi').all()) {
      if (await tabel.isVisible() && await tabel.locator('thead').evaluate((n) => n.getBoundingClientRect().height > 2)) masalah.push('thead tabel kartu masih tampil');
    }
  }
  if (lebar === 1280 && await page.locator('.ah-panel').count()) {
    const posisi = await page.locator('.ah-panel').evaluate((n) => getComputedStyle(n).position);
    if (posisi !== 'fixed') masalah.push('panel bukan panel samping');
  }
  for (const tombol of await page.locator('.ah-page-head__actions .btn, .ah-actbar .btn, .ah-filter__actions .btn, .ah-act > summary.btn:not(.btn-link), .ah-kisi-pager .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  const isianId = await page.locator('main input[name$="_id"]:not([type="hidden"]):not([type="checkbox"])').count();
  if (isianId > 0) masalah.push(`${isianId} isian ID`);
  cek(masalah.length === 0, `T7-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}

const S = IDS.santri;
const RM = IDS.rencana_mapel;
const fiqih = RM[String(IDS.mapel.fiqih)];
const LAYAR = {
  sbx_pendidikan: {
    'R-1': `/portal/rencana_nilai.php?tahun_ajaran_id=${IDS.tahun}&kelas_id=${IDS.kelas}`,
    'R-3': `/portal/nilai_semester.php?pengganti=1&rencana_mapel_id=${fiqih}`,
    'R-5': `/portal/verifikasi_semester.php?rencana_id=${IDS.rencana}`,
    'R-5-panel': `/portal/verifikasi_semester.php?rencana_id=${IDS.rencana}&santri_id=${S[9]}&bahan=nilai:${fiqih}`,
    'R-6': `/portal/rapor_semester.php?rencana_id=${IDS.rencana}`,
    'R-6-panel': `/portal/rapor_semester.php?rencana_id=${IDS.rencana}&santri_id=${S[1]}`,
  },
  sbx_admin: {
    'R-1-salin': `/portal/rencana_nilai.php?tahun_ajaran_id=${IDS.tahun}&kelas_id=${IDS.kelas_baru}&salin=1`,
    'R-7': `/portal/rapor_penanda.php?rencana_id=${IDS.rencana}`,
    'R-7-tambah': `/portal/rapor_penanda.php?tambah=1`,
    'M-5': '/admin/admin_mata_pelajaran.php',
    'M-5-panel': `/admin/admin_mata_pelajaran.php?action=edit&id=${IDS.mapel.tafsir}`,
  },
  sbx_guru_mapel: {
    'R-2': `/portal/nilai_semester.php?rencana_mapel_id=${fiqih}`,
    'R-2-panel': `/portal/nilai_semester.php?rencana_mapel_id=${fiqih}&santri_id=${S[10]}`,
  },
  sbx_pengurus_a: { 'R-4': `/portal/sikap_semester.php?tahun_ajaran_id=${IDS.tahun}`, 'R-4-panel': `/portal/sikap_semester.php?rencana_id=${IDS.rencana}&santri_id=${S[11]}` },
  sbx_murobi_c: { 'R-4-murobi': '/portal/sikap_semester.php?mode=murobi' },
  sbx_t7_ortu: { 'R-8': `/portal/rapor_wali.php?anak=${S[1]}`, 'R-8-versi-lama': `/portal/rapor_wali.php?id=${IDS.rapor}&versi=1` },
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ---------------------------------------------------------------- T7-A
  for (const [user, layar] of Object.entries(LAYAR)) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [nama, alamat] of Object.entries(layar)) {
        const r = await page.goto(BASE + alamat);
        if (r.status() !== 200) { cek(false, `T7-A ${nama} ${lebar}px`, `HTTP ${r.status()}`); continue; }
        await periksaLayar(page, nama, lebar);
        await page.screenshot({ path: `${OUT}/${nama}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
      }
      await context.close();
    }
  }
  if (process.env.HANYA_LAYAR) throw new Error('HANYA_LAYAR');

  // ---------------------------------------------------------------- T7-B
  {
    const { context, page } = await konteks(browser, 'sbx_guru_mapel', 1280);
    await page.goto(`${BASE}/portal/nilai_semester.php?rencana_mapel_id=${fiqih}`);
    const baris = page.locator(`tr[data-santri="${S[21]}"]`);
    cek(await page.locator('table.ah-kisi tbody tr').count() === 30, 'T7-B kisi menampilkan 30 santri di satu layar');
    await baris.locator('input[data-komponen][name$="[Tengah:0]"]').fill('80');
    await baris.locator('input[data-komponen][name$="[Akhir:0]"]').fill('90');
    const akhir = await baris.locator('[data-rapor-akhir]').innerText();
    const h1 = Number(await baris.locator('input[data-komponen][name$="[Harian:1]"]').inputValue());
    const harap = Math.floor((2 * (400 * h1 + 300 * 170) + 1000) / 2000);
    cek(akhir === String(harap), 'T7-B nilai akhir dihitung langsung (40/30/30, half-up)', `${akhir} vs ${harap}`);
    cek((await page.locator('[data-rapor-sbar-text]').innerText()).includes('Ada perubahan belum disimpan'), 'T7-B bilah bawah: "Ada perubahan belum disimpan"');
    cek(await page.evaluate(() => document.querySelector('form[data-rapor-kisi]').classList.contains('is-dirty')), 'T7-B perubahan ditandai (peringatan saat meninggalkan halaman)');
    cek(!(await page.locator('[data-rapor-koreksi]').isVisible()), 'T7-B isian baru tidak meminta alasan koreksi');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Simpan draf' }).click()]);
    cek((await page.content()).includes('Draf nilai 1 santri tersimpan'), 'T7-B Simpan draf menyimpan santri yang berubah saja');
    await page.locator(`tr[data-santri="${S[13]}"] input[data-komponen][name$="[Harian:1]"]`).fill('70');
    cek(await page.locator('[data-rapor-koreksi]').isVisible() && await page.locator('#alasan-koreksi-kisi').evaluate((n) => n.required), 'T7-B mengubah nilai tersimpan memunculkan alasan koreksi wajib');
    await page.getByRole('button', { name: 'Simpan draf' }).click();
    await page.waitForTimeout(300);
    cek(page.url().includes('rencana_mapel_id') && await page.locator('#alasan-koreksi-kisi').evaluate((n) => n === document.activeElement), 'T7-B tanpa alasan: tidak terkirim, fokus ke alasan');
    await page.fill('#alasan-koreksi-kisi', 'Salah ketik nilai harian (uji browser).');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Simpan draf' }).click()]);
    cek((await page.content()).includes('Draf nilai 1 santri tersimpan') && await page.locator(`tr[data-santri="${S[13]}"] input[data-komponen][name$="[Harian:1]"]`).inputValue() === '70', 'T7-B koreksi beralasan tersimpan');
    await page.locator(`tr[data-santri="${S[13]}"] th a`).click();
    await page.waitForSelector('.ah-panel');
    const panel = await page.locator('.ah-panel').innerText();
    cek(panel.includes('Riwayat revisi') && panel.includes('SBX Guru Mapel') && panel.includes('Salah ketik'), 'T7-B panel santri: riwayat revisi dengan nama pelaku dan alasan');
    await page.screenshot({ path: `${OUT}/T7-B-panel-1280.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
  }

  // ---------------------------------------------------------------- T7-C
  {
    const { context, page } = await konteks(browser, 'sbx_guru_mapel', 1280);
    await page.goto(`${BASE}/portal/nilai_semester.php?rencana_mapel_id=${fiqih}`);
    const tombol = page.locator('#serahkan-form button');
    const n = Number(((await tombol.innerText()).match(/\((\d+)\)/) || [])[1] || 0);
    await tombol.click();
    cek(await page.locator('.ah-bulk progress').isVisible(), 'T7-C bilah kemajuan tampil');
    const ringkas = await tungguMassal(page);
    cek(n >= 8 && ringkas.startsWith(`${n} berhasil · 0 gagal`), 'T7-C semua yang lengkap diserahkan satu per satu', `${n}: ${ringkas}`);
    await page.screenshot({ path: `${OUT}/T7-C-massal-1280.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
  }

  // ---------------------------------------------------------------- T7-D
  {
    const { context, page } = await konteks(browser, 'sbx_guru_mapel', 375);
    await page.goto(`${BASE}/portal/nilai_semester.php?rencana_mapel_id=${fiqih}`);
    const terlihat = await page.locator('table.ah-kisi tbody tr').evaluateAll((rs) => rs.filter((r) => r.offsetParent !== null).length);
    cek(terlihat === 1 && (await page.locator('.ah-kisi-pager__pos').innerText()) === '1 dari 30', 'T7-D HP: satu santri per kartu, "1 dari 30"');
    await page.getByRole('button', { name: 'Berikutnya' }).click();
    cek((await page.locator('.ah-kisi-pager__pos').innerText()) === '2 dari 30' && await page.locator('table.ah-kisi tbody tr.is-current th').innerText().then((t) => t.includes('Santri 02')), 'T7-D Berikutnya pindah ke santri kedua');
    await page.screenshot({ path: `${OUT}/T7-D-kartu-375.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
  }

  // ---------------------------------------------------------------- T7-E
  {
    const { context, page } = await konteks(browser, 'sbx_murobi_c', 768);
    await page.goto(`${BASE}/portal/sikap_semester.php?mode=murobi&status=perlu_diketahui`);
    const n = await page.locator('input[name="pilih[]"]').count();
    await page.locator('input[data-ah-select-all]').check();
    cek(await page.locator('.ah-selbar').isVisible(), 'T7-E centang semua memunculkan bilah "Tandai diketahui"');
    await page.locator('.ah-selbar').getByRole('button', { name: 'Tandai diketahui' }).click();
    const ringkas = await tungguMassal(page);
    cek(n >= 1 && ringkas.startsWith(`${n} berhasil · 0 gagal`), 'T7-E sikap ditandai diketahui satu per satu', `${n}: ${ringkas}`);
    await context.close();
  }

  // ---------------------------------------------------------------- T7-F
  {
    const { context, page } = await konteks(browser, 'sbx_pendidikan', 1280);
    await page.goto(`${BASE}/portal/verifikasi_semester.php?rencana_id=${IDS.rencana}`);
    cek(await page.locator('.ah-legend').isVisible() && await page.locator('table.ah-matrix tbody tr').count() === 30, 'T7-F matriks 30 santri dengan legenda');
    await page.locator(`table.ah-matrix tbody tr:nth-child(9) a.ah-sel`).first().click();
    await page.waitForSelector('.ah-panel');
    cek((await page.locator('.ah-panel').innerText()).includes('Perlu perbaikan'), 'T7-F klik sel membuka panel detail bahan');
    await page.goto(`${BASE}/portal/verifikasi_semester.php?rencana_id=${IDS.rencana}`);
    const ringkasan = await page.locator('#verifikasi-semua > summary').innerText();
    const n = Number((ringkasan.match(/\((\d+)\)/) || [])[1] || 0);
    await page.locator('#verifikasi-semua > summary').click();
    await dialogForm(page).getByRole('button', { name: /Verifikasi \d+ bahan/ }).click();
    const ringkas = await tungguMassal(page);
    cek(n > 0 && ringkas.startsWith(`${n} berhasil · 0 gagal`), 'T7-F "Verifikasi semua yang diserahkan" per bahan dengan bilah kemajuan', `${n}: ${ringkas}`);
    await page.screenshot({ path: `${OUT}/T7-F-massal-1280.jpg`, type: 'jpeg', quality: 70 });
    await context.close();
  }

  // ---------------------------------------------------------------- T7-G
  {
    const { context, page } = await konteks(browser, 'sbx_pendidikan', 1280);
    await page.goto(`${BASE}/portal/rapor_semester.php?rencana_id=${IDS.rencana}`);
    await page.locator('input[type="checkbox"][name="pilih[]"]').first().check();
    cek(await page.locator('.ah-selbar').isVisible() && (await page.locator('[data-ah-selcount]').innerText()).includes('1'), 'T7-G centang memunculkan bilah "1 santri dipilih"');
    await page.locator('input[type="checkbox"][name="pilih[]"]').first().uncheck();
    const ringkasan = await page.locator('#terbit-siap > summary').innerText();
    const n = Number((ringkasan.match(/\((\d+)\)/) || [])[1] || 0);
    await page.locator('#terbit-siap > summary').click();
    await dialogForm(page).getByRole('button', { name: /Terbitkan \d+ rapor/ }).click();
    const ringkas = await tungguMassal(page);
    cek(n >= 5 && ringkas.startsWith(`${n} berhasil · 0 gagal`), 'T7-G "Terbitkan yang siap": PDF dibuat satu santri per permintaan', `${n}: ${ringkas}`);
    await page.screenshot({ path: `${OUT}/T7-G-terbit-1280.jpg`, type: 'jpeg', quality: 70 });
    await page.goto(`${BASE}/portal/rapor_semester.php?rencana_id=${IDS.rencana}&tampil=terbit`);
    cek(await page.locator('table.ah-table tbody tr').count() === n + 2, 'T7-G chip "Terbit" memuat rapor yang baru terbit', String(await page.locator('table.ah-table tbody tr').count()));
    await context.close();
  }

  // ---------------------------------------------------------------- T7-H
  {
    // Pendidikan: penugasannya hanya berlaku pada semester aktif, jadi tombol salin tidak tampil.
    const pend = await konteks(browser, 'sbx_pendidikan', 768);
    await pend.page.goto(`${BASE}/portal/rencana_nilai.php?tahun_ajaran_id=${IDS.tahun}&kelas_id=${IDS.kelas_baru}`);
    cek(await pend.page.getByRole('link', { name: 'Salin dari semester lalu' }).count() === 0, 'T7-H Pendidikan tanpa cakupan semester lalu: tombol salin tidak tampil');
    await pend.context.close();
    const { context, page } = await konteks(browser, 'sbx_admin', 768);
    await page.goto(`${BASE}/portal/rencana_nilai.php?tahun_ajaran_id=${IDS.tahun}&kelas_id=${IDS.kelas_baru}`);
    await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: 'Salin dari semester lalu' }).click()]);
    const main = await teksMain(page);
    cek(main.includes('Formulir diisi dari rencana') && main.includes('Belum tersimpan'), 'T7-H salin mengisi formulir tanpa menyimpan');
    cek(main.includes('SBX Guru Lama sudah nonaktif'), 'T7-H guru semester lalu yang nonaktif ditandai untuk diganti');
    const terpilih = await page.locator('select[data-rapor-mapel]').evaluateAll((ss) => ss.map((s) => s.selectedOptions[0]?.textContent.trim()).filter((t) => t && !t.startsWith('—')));
    cek(terpilih.join(',') === 'Fiqih,Nahwu,Tafsir', 'T7-H mapel semester lalu terisi', terpilih.join(','));
    const opsiGuru = await page.locator('tr:has(select[data-rapor-mapel] option[selected]:text("Fiqih")) select[data-rapor-guru] option').evaluateAll((os) => os.filter((o) => o.value && !o.hidden).map((o) => o.textContent));
    cek(opsiGuru.length > 0 && opsiGuru.every((t) => t.includes('Fiqih')), 'T7-H pilihan guru tersaring sesuai mapel', opsiGuru.join('|'));
    const sebelum = await page.locator('tbody[data-rapor-mapel-rows] tr').count();
    await page.getByRole('button', { name: 'Tambah mapel' }).click();
    cek(await page.locator('tbody[data-rapor-mapel-rows] tr').count() === sebelum + 1, 'T7-H "Tambah mapel" menambah baris');
    cek(await page.locator('#ttd-pendidikan img').evaluate((img) => img.complete && img.naturalWidth > 0), 'T7-H pratinjau tanda tangan termuat lewat jalur berpenjaga');
    await Promise.all([page.waitForNavigation(), page.locator('.ah-actbar').getByRole('button', { name: 'Simpan rencana' }).click()]);
    cek((await page.content()).includes('Rencana semester tersimpan') && (await teksMain(page)).includes('Mata pelajaran wajib (3)'), 'T7-H rencana hasil salinan tersimpan lewat layanan');
    // Keputusan 23 Okt 2026: mapel tanpa nilai dapat dihapus dari rencana.
    const hapus = page.locator('tr:has(select[data-rapor-mapel] option[selected]:text("Tafsir")) input[data-rapor-hapus]');
    await hapus.check();
    cek(await page.locator('tr.is-hapus').count() === 1, 'T7-H "Hapus dari rencana" meredupkan baris');
    await Promise.all([page.waitForNavigation(), page.locator('.ah-actbar').getByRole('button', { name: 'Simpan rencana' }).click()]);
    cek((await teksMain(page)).includes('Mata pelajaran wajib (2)'), 'T7-H mapel tanpa nilai terhapus dari rencana');
    await context.close();
  }

  // ---------------------------------------------------------------- T7-I
  {
    const { context, page } = await konteks(browser, 'sbx_t7_ortu', 375);
    await page.goto(`${BASE}/portal/rapor_wali.php?id=${IDS.rapor}`);
    const main = await teksMain(page);
    cek(/menggantikan versi 1/.test(main) && (await page.locator('.ah-rapor-doc').count()) === 1, 'T7-I hanya versi terbaru tampil dengan catatan "menggantikan versi 1"');
    await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: /Versi sebelumnya \(1\)/ }).click()]);
    cek((await teksMain(page)).includes('Sudah diganti') && (await page.locator('.ah-rapor-doc').count()) === 0, 'T7-I versi lama bertanda "Sudah diganti" tanpa isi');
    await context.close();
  }

  // ---------------------------------------------------------------- T7-J
  {
    const { context, page } = await konteks(browser, 'sbx_guru_mapel', 1280, false);
    const nahwu = RM[String(IDS.mapel.nahwu)];
    await page.goto(`${BASE}/portal/nilai_semester.php?rencana_mapel_id=${nahwu}`);
    await page.locator(`tr[data-santri="${S[25]}"] input[data-komponen][name$="[Harian:1]"]`).fill('77');
    await page.locator(`tr[data-santri="${S[25]}"] input[data-komponen][name$="[Tengah:0]"]`).fill('78');
    await page.locator(`tr[data-santri="${S[25]}"] input[data-komponen][name$="[Akhir:0]"]`).fill('79');
    await Promise.all([page.waitForNavigation(), page.locator('.ah-actbar').getByRole('button', { name: 'Simpan draf' }).click()]);
    cek((await page.content()).includes('Draf nilai 1 santri tersimpan'), 'T7-J tanpa JavaScript: kisi tersimpan');
    await Promise.all([page.waitForNavigation(), page.locator('#serahkan-form button').click()]);
    cek(/\d+ nilai santri diserahkan/.test(await page.content()), 'T7-J tanpa JavaScript: "Serahkan yang lengkap" diproses server berurutan');
    await context.close();
  }

  // ---------------------------------------------------------------- T7-K
  cek(galat.length === 0, 'T7-K tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
  cek(bawaan.length === 0, 'T7-K tanpa confirm()/alert() bawaan peramban', bawaan.slice(0, 3).join(' | '));
} catch (e) {
  if (e.message !== 'HANYA_LAYAR') throw e;
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
