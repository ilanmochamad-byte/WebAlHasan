import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 — Fase 1 (fondasi, Masuk, Beranda,
// Profil/Akun) di Expo WEB (Chromium) melawan API/DB uji nyata. Ini bukan
// perangkat native; skala teks sistem diuji di emulator/simulator.
//
//   PIF1_DB=test_rtv2_app_test bash tests/profil_impor_f1_db.sh
//   PIF2_DB=test_rtv2_app_test php tests/profil_impor_f2_app_seed.php > ids.json
//   RTV2_DB=test_rtv2_app_test php tests/redesain_v2_fase1_seed.php
//   DB_NAME=test_rtv2_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port 8973 --clear &)
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8973 OUT_DIR=… node uji-redesain-v2-fase1.mjs
//
//   A  Masuk: logo lama, tanpa logo teks K-9, tanpa ←, galat di kartu, tanpa daftar/sosial
//   B  Beranda tujuh peran: kepala logo + lonceng tanpa ←, kartu identitas isi pertama,
//      ringkasan = data API yang sudah dimuat (K-3), ubin 3 kolom, bilah ≤ 4 tab tanpa "+"
//   C  Profil: ← "Kembali ke Beranda", ProfileHeader, strip angka = API, Keluar
//   D  Edit profil & Ganti password: kepala stack (← · judul tengah), ← ke halaman sebelumnya;
//      dibuka langsung (tanpa riwayat) ← membuka Beranda; tombol kembali peramban tetap bekerja
//   E  Ganti password wajib: tanpa ← (pengecualian K-10), tombol Keluar
//   F  Layar yang belum diubah (Jadwal, Perizinan, Laporan, Notifikasi) tetap terbuka
//   G  Mode gelap dan lebar 375/768/1280 tanpa luapan isi
//   H  Pratinjau komponen fondasi (rute sementara; dilewati bila tidak dipasang)
//   I  Nol permintaan ke luar 127.0.0.1; bundel tanpa alamat produksi; tanpa pageerror
const web = process.env.RTV2_WEB, app = process.env.RTV2_APP;
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) throw Error('Hanya 127.0.0.1');
const out = process.env.OUT_DIR || '/tmp/rtv2-f1';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0, lewati = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const luar = [], galatHalaman = [];

async function loginApi(user, password = PASS) {
  const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password, device_name: 'uji-rtv2-api' }) });
  return (await r.json()).data?.token;
}
async function api(token, p) {
  const r = await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${token}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}

const browser = await chromium.launch({ headless: true });
async function konteks({ lebar = 375, tinggi = 812, skema = 'light' } = {}) {
  const ctx = await browser.newContext({ viewport: { width: lebar, height: tinggi }, colorScheme: skema, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  page.on('pageerror', e => galatHalaman.push(e.message));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    try {
      const response = await route.fetch();
      await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { /* konteks ditutup */ }
  });
  return page;
}
async function masuk(user, opsi = {}, password = PASS) {
  const page = await konteks(opsi);
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(password);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 30000 });
  return page;
}
const kartu = p => p.getByTestId('kartu-identitas');
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const jalur = p => new URL(p.url()).pathname;
const foto = (p, nama) => p.screenshot({ path: path.join(out, `${nama}.png`), fullPage: true });
const tombolKembali = p => p.getByRole('button', { name: /^Kembali( ke Beranda)?$/ });
/**
 * Seluruh layar termasuk bilah tab harus muat, kecuali isi wadah yang
 * sengaja digeser ke samping (FilterPills).
 */
async function tanpaLuapan(p, nama) {
  const peluap = await p.evaluate(() => {
    return [...document.querySelectorAll('body *')]
      .filter(el => el.getBoundingClientRect().right > innerWidth + 1)
      .filter(el => { for (let a = el.parentElement; a; a = a.parentElement) if (['auto', 'scroll'].includes(getComputedStyle(a).overflowX)) return a.getBoundingClientRect().right > innerWidth + 1; return true; })
      .map(el => (el.innerText || el.tagName).slice(0, 30));
  });
  ok(peluap.length === 0, `${nama}: isi tanpa luapan horizontal${peluap.length ? ` (peluap: ${peluap.slice(0, 3).join(' | ')})` : ''}`);
}
/** Hitung hanya elemen yang terlihat: layar tab lain tetap ada di DOM (tersembunyi). */
const terlihat = l => l.filter({ visible: true }).count();
const kotak = async l => (await l.first().boundingBox()) ?? { x: -1, y: -1, width: 0, height: 0 };

try {
  console.log('\n=== A. Masuk ===');
  {
    const p = await konteks();
    await p.goto(app + '/login');
    await p.getByPlaceholder('Masukkan username').waitFor({ timeout: 120000 });
    ok(await p.getByRole('img', { name: 'Alhasan Apps', exact: true }).count() === 1, 'Masuk memakai logo Al Hasan yang sekarang');
    ok(await p.getByRole('img', { name: 'Alhasan apps', exact: true }).count() === 0, 'Logo teks K-9 tidak dipakai di layar Masuk');
    ok(await tombolKembali(p).count() === 0, 'Masuk tanpa tombol ← (pengecualian K-10)');
    const t = await teks(p);
    ok(t.includes('Masuk ke akun pesantren Anda') && t.includes('Lupa password? Hubungi admin pesantren'), 'Judul dan petunjuk lupa password tampil');
    ok(!/Daftar|Google|Apple|Microsoft|Remember|Ingat saya/i.test(t), 'Tanpa daftar akun, masuk sosial, atau "ingat saya"');
    const tinggi = (await kotak(p.getByRole('button', { name: 'Masuk', exact: true }))).height;
    ok(tinggi >= 52, `Tombol utama setinggi ≥ 52 pt (${tinggi})`);
    await p.getByPlaceholder('Masukkan username').fill('sbx_admin');
    await p.getByPlaceholder('Masukkan password').fill('SalahSekali#1');
    await p.getByRole('button', { name: 'Masuk', exact: true }).click();
    await p.getByText(/salah|tidak/i).first().waitFor({ timeout: 15000 });
    ok(jalur(p) === '/login', 'Password salah: tetap di Masuk dengan pesan galat');
    await foto(p, 'masuk-galat-375');
    await tanpaLuapan(p, 'Masuk');
    await p.context().close();
  }

  console.log('\n=== B. Beranda tujuh peran ===');
  const PERAN = [
    ['sbx_guru_biasa', 'Guru'], ['sbx_pengurus_biasa', 'Pengurus (hanya perizinan)'], ['sbx_ortu_a', 'Orang tua'],
    ['sbx_v6_bsa', 'Petugas BSA'], ['sbx_v5_petugas', 'Petugas kesehatan'], ['sbx_pengurus_b', 'Keamanan'], ['sbx_admin', 'Admin'],
  ];
  for (const [user, label] of PERAN) {
    const p = await masuk(user);
    await kartu(p).waitFor({ timeout: 30000 });
    const token = await loginApi(user);
    const profil = (await api(token, '/profile')).json.data;
    const pribadi = (await api(token, '/profile/pribadi')).json.data;
    const aksesJadwal = profil.roles.includes('guru') || profil.roles.includes('admin');
    await kartu(p).getByText(pribadi.pribadi.nama, { exact: true }).waitFor({ timeout: 20000 });
    await p.waitForTimeout(2000);

    const logo = p.getByRole('img', { name: 'Alhasan apps', exact: true });
    const kLogo = await kotak(logo);
    ok(await logo.count() === 1 && Math.abs(kLogo.height - 28) <= 1 && Math.abs(kLogo.width / kLogo.height - 2161 / 761) < 0.06,
      `${label}: logo "Alhasan apps" di kepala Beranda, tinggi ${kLogo.height} pt, rasio ${(kLogo.width / kLogo.height).toFixed(2)}`);
    const lonceng = p.getByRole('button', { name: /^Notifikasi/ });
    const kLonceng = await kotak(lonceng);
    const kKartu = await kotak(kartu(p));
    ok(await lonceng.count() === 1 && kLonceng.y + kLonceng.height <= kKartu.y + 1 && kLonceng.x > kLogo.x + kLogo.width && kLonceng.width >= 44 && kLonceng.height >= 44,
      `${label}: lonceng di kanan kepala, di atas kartu identitas, target ≥ 44 pt`);
    ok(await tombolKembali(p).count() === 0, `${label}: Beranda tanpa tombol ← (K-9)`);
    const posisi = await p.evaluate(() => {
      const k = document.querySelector('[data-testid="kartu-identitas"]');
      return { pertama: k.parentElement.firstElementChild === k };
    });
    ok(posisi.pertama && kLogo.y < kKartu.y, `${label}: kartu identitas tetap isi pertama sesudah kepala`);

    const t = await teks(p);
    if (aksesJadwal) {
      const hariIni = (await api(token, '/schedules/today')).json.data;
      const belum = hariIni.schedules.filter(s => s.meeting?.status !== 'Selesai').length;
      const sesi = await p.getByLabel(`Sesi hari ini: ${hariIni.schedules.length}`, { exact: true }).count();
      const sisa = await p.getByLabel(`Sesi belum selesai diabsen: ${belum}`, { exact: true }).count();
      ok(sesi === 1 && sisa === 1, `${label}: ringkasan = /schedules/today yang sudah dimuat (sesi ${hariIni.schedules.length}, belum selesai ${belum})`);
    } else {
      const tugas = await p.getByLabel(`Penugasan aktif: ${pribadi.penugasan.length}`, { exact: true }).count();
      const notif = await p.getByLabel(/^Notifikasi belum dibaca: \d+$/).count();
      ok(tugas === 1 && notif === 1 && !t.includes('Sesi hari ini'), `${label}: ringkasan = profil/notifikasi yang sudah dimuat (penugasan ${pribadi.penugasan.length}), tanpa angka jadwal`);
    }
    ok(!/[↑↓]\s*\d+\s*%/.test(t), `${label}: tanpa angka pembanding karangan`);

    // Ubin: tepat 3 kolom pada baris pertama, sama lebar di semua baris.
    const ubin = await p.evaluate(() => {
      const judul = [...document.querySelectorAll('div')].find(d => d.children.length === 0 && d.textContent === 'Menu');
      if (!judul) return null;
      let bagian = judul; while (bagian && bagian.querySelectorAll('[role="button"]').length === 0) bagian = bagian.parentElement;
      const kotak = [...bagian.querySelectorAll('[role="button"]')].map(b => b.getBoundingClientRect()).map(r => ({ x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width) }));
      const barisPertama = kotak.filter(k => k.y === kotak[0].y);
      return { jumlah: kotak.length, kolom: new Set(kotak.map(k => k.x)).size, barisPertama: barisPertama.length, lebar: [...new Set(kotak.map(k => k.w))] };
    });
    if (ubin) {
      ok(ubin.kolom <= 3 && ubin.barisPertama === Math.min(3, ubin.jumlah) && ubin.lebar.length === 1,
        `${label}: ${ubin.jumlah} ubin, kisi tetap 3 kolom, lebar seragam ${ubin.lebar.join('/')} px`);
    } else ok(t.includes('Akun ini belum memiliki menu'), `${label}: tanpa menu → keterangan`);

    const tautanTab = p.getByTestId('bilah-tab-web').getByRole('link');
    const tab = await tautanTab.allInnerTexts();
    ok(tab.length >= 2 && tab.length <= 4 && tab[0] === 'Beranda' && tab.at(-1) === 'Profil' && !tab.includes('+'), `${label}: bilah ${tab.length} tab (${tab.join(', ')}), tanpa tombol + (K-2)`);
    const targetTab = await tautanTab.evaluateAll(links => links.every(link => {
      const { width, height } = link.getBoundingClientRect();
      return width >= 44 && height >= 44;
    }));
    ok(tab.length > 0 && targetTab, `${label}: setiap target tab ≥ 44 × 44 px`);
    await tanpaLuapan(p, `Beranda ${label}`);
    await foto(p, `beranda-${user}-375-terang`);

    console.log(`--- C. Profil (${label}) ---`);
    await kartu(p).getByRole('button', { name: /Buka profil/ }).click();
    await p.waitForURL(u => u.pathname === '/profil');
    await p.getByText('Perangkat & push', { exact: false }).first().waitFor({ timeout: 20000 });
    await p.waitForTimeout(1200);
    const kembali = p.getByRole('button', { name: 'Kembali ke Beranda', exact: true });
    const kKembali = await kotak(kembali);
    const kJudul = await kotak(p.getByRole('heading', { name: 'Profil', exact: true }));
    ok(await kembali.count() === 1 && kKembali.width >= 44 && kKembali.height >= 44 && kKembali.y + kKembali.height <= kJudul.y + 1,
      `${label}: Profil punya ← "Kembali ke Beranda" ≥ 44 pt di atas judul besar`);
    const tp = await teks(p);
    ok(tp.includes(pribadi.pribadi.nama) && tp.includes('@' + user) && pribadi.status.every(s => tp.includes(s.label)), `${label}: kepala profil memuat nama, username, dan status dari API`);
    const kelas = new Set(pribadi.mengajar.map(m => m.kelas)).size, mapel = new Set(pribadi.mengajar.map(m => m.mata_pelajaran)).size;
    if (pribadi.penugasan.length > 0 || pribadi.mengajar.length > 0) {
      const a = await terlihat(p.getByLabel(`Penugasan aktif: ${pribadi.penugasan.length}`, { exact: true }));
      const b = pribadi.mengajar.length ? await terlihat(p.getByLabel(`Mata pelajaran: ${mapel}`, { exact: true })) + await terlihat(p.getByLabel(`Kelas: ${kelas}`, { exact: true })) : 2;
      ok(a === 1 && b === 2, `${label}: strip angka = API (penugasan ${pribadi.penugasan.length}${pribadi.mengajar.length ? `, mapel ${mapel}, kelas ${kelas}` : ''})`);
    } else {
      ok(await terlihat(p.getByLabel(/^Penugasan aktif: /)) === 0, `${label}: tanpa penugasan/mapel → strip angka tidak digambar`);
    }
    // Tindak lanjut TL-03 (§4.2): Akun dan Pemberitahuan terpisah; Data diri berupa baris ›.
    ok(['Data diri', 'Rincian penugasan aktif', 'Akun', 'Pemberitahuan', 'Perangkat & push', 'Tampilan', 'Tentang', 'Keluar dari aplikasi'].every(x => tp.includes(x)) && !tp.includes('Akun & pemberitahuan'), `${label}: kelompok Profil lengkap`);
    await tanpaLuapan(p, `Profil ${label}`);
    await foto(p, `profil-${user}-375-terang`);
    await kembali.click();
    await kartu(p).waitFor({ timeout: 20000 });
    ok(jalur(p) === '/', `${label}: ← di Profil membuka tab Beranda`);
    await p.context().close();
  }

  console.log('\n=== D. Edit profil & Ganti password: kepala stack dan ← ===');
  {
    const p = await masuk('sbx_pengurus_biasa');
    await kartu(p).getByRole('button', { name: /Buka profil/ }).click();
    await p.waitForURL(u => u.pathname === '/profil');
    for (const [tombol, tujuan, judul, tanda] of [
      ['Edit profil', '/akun/edit-profil', 'Edit profil', 'Diatur admin (hanya baca)'],
      [/^Ganti password/, '/akun/ganti-password', 'Ganti password', 'Ulangi password baru'],
    ]) {
      await p.getByRole('button', { name: tombol }).first().click();
      await p.waitForURL(u => u.pathname === tujuan);
      await p.getByText(tanda, { exact: false }).first().waitFor({ timeout: 20000 });
      const kb = p.getByRole('button', { name: 'Kembali', exact: true });
      const kKb = await kotak(kb), kJ = await kotak(p.getByRole('heading', { name: judul, exact: true }));
      const tengah = kJ.x + kJ.width / 2;
      ok(await kb.count() === 1 && kKb.width >= 44 && kKb.height >= 44 && kKb.x < kJ.x && Math.abs(tengah - 375 / 2) <= 2 && Math.abs((kKb.y + kKb.height / 2) - (kJ.y + kJ.height / 2)) <= 4,
        `${judul}: kepala stack — ← ≥ 44 pt di kiri, judul di tengah (pusat ${tengah.toFixed(1)} dari 187,5)`);
      await tanpaLuapan(p, judul);
      await foto(p, `${tujuan.split('/').pop()}-375-terang`);
      await kb.click();
      await p.waitForURL(u => u.pathname === '/profil', { timeout: 15000 });
      ok(true, `${judul}: ← kembali ke halaman sebelumnya (Profil)`);
    }
    // Edit profil: kelompok dan isian terkunci.
    await p.getByRole('button', { name: 'Edit profil' }).click();
    await p.getByText('Diatur admin (hanya baca)', { exact: false }).first().waitFor({ timeout: 20000 });
    const te = await teks(p);
    ok(te.indexOf('Data diri') < te.indexOf('Kontak') && te.indexOf('Kontak') < te.indexOf('Alamat rumah') && te.indexOf('Alamat rumah') < te.indexOf('Diatur admin'), 'Edit profil: kelompok Data diri → Kontak → Alamat → Diatur admin');
    ok(await p.getByLabel('Username: @sbx_pengurus_biasa, hanya dapat diubah admin', { exact: true }).count() === 1 && await p.locator('input[aria-label="Username"]').count() === 0, 'Username tampil sebagai baris terkunci, bukan isian');
    ok(await p.getByRole('button', { name: /foto profil$/ }).count() === 1, 'Avatar besar berlencana kamera dapat diketuk');
    const simpan = p.getByRole('button', { name: 'Simpan perubahan' });
    const kS = await kotak(simpan);
    ok(kS.y + kS.height <= 812 && kS.y > 812 - 140, 'Tombol "Simpan perubahan" tetap di bawah layar');
    await p.goBack();
    await p.waitForURL(u => u.pathname === '/profil', { timeout: 15000 });
    ok(true, 'Tombol kembali peramban tetap bekerja dari Edit profil');

    // Dibuka langsung tanpa riwayat (web dimuat ulang / tautan).
    for (const [tujuan, tanda] of [['/akun/edit-profil', 'Diatur admin (hanya baca)'], ['/akun/ganti-password', 'Ulangi password baru'], ['/notifikasi/perangkat', null]]) {
      await p.goto(app + tujuan);
      if (tanda) {
        await p.getByText(tanda, { exact: false }).first().waitFor({ timeout: 30000 });
        await p.getByRole('button', { name: 'Kembali', exact: true }).click();
        await kartu(p).waitFor({ timeout: 20000 });
        ok(jalur(p) === '/', `${tujuan} dibuka langsung: ← membuka Beranda (tanpa riwayat)`);
      } else {
        await p.waitForTimeout(2500);
        ok(jalur(p) === tujuan && galatHalaman.length === 0, `${tujuan} (belum diubah) dibuka langsung tetap terbuka`);
      }
    }
    await p.context().close();
  }

  console.log('\n=== E. Ganti password wajib ===');
  {
    const p = await masuk('sbx_murobi_c', {}, 'Sementara#2026A');
    await p.waitForURL(u => u.pathname === '/ganti-password-wajib', { timeout: 20000 });
    await p.getByRole('heading', { name: 'Buat password baru', exact: true }).waitFor({ timeout: 20000 });
    const t = await teks(p);
    ok(t.includes('Ganti password sementara') && t.includes('@sbx_murobi_c'), 'Judul "Buat password baru" + penjelas password sementara');
    ok(await tombolKembali(p).count() === 0 && await p.getByRole('button', { name: 'Keluar', exact: true }).count() === 1, 'Tanpa ← (pengecualian K-10); tombol Keluar tersedia');
    ok(await p.getByRole('img', { name: 'Alhasan apps', exact: true }).count() === 0, 'Tanpa logo teks K-9');
    await tanpaLuapan(p, 'Ganti password wajib');
    await foto(p, 'ganti-password-wajib-375-terang');
    await p.context().close();
  }

  console.log('\n=== F. Layar yang belum diubah tetap terbuka ===');
  {
    const p = await masuk('sbx_admin');
    await kartu(p).waitFor();
    for (const [tujuan, tanda] of [['/schedules', 'Jadwal'], ['/perizinan', 'Perizinan'], ['/reports', 'Laporan kehadiran'], ['/notifikasi', 'Notifikasi']]) {
      const sebelum = galatHalaman.length;
      await p.goto(app + tujuan);
      await p.getByText(tanda, { exact: true }).first().waitFor({ timeout: 30000 });
      await p.waitForTimeout(1500);
      ok(galatHalaman.length === sebelum, `${tujuan}: terbuka tanpa pageerror`);
      await foto(p, `lain${tujuan.replace(/\W+/g, '-')}-375-terang`);
    }
    await p.goto(app + '/reports');
    await p.getByText('Laporan kehadiran', { exact: true }).waitFor({ timeout: 30000 });
    await p.getByRole('button', { name: 'Kembali' }).first().click();
    await kartu(p).waitFor({ timeout: 20000 });
    ok(jalur(p) === '/', 'Laporan (kepala lama): tombol Kembali tetap ke Beranda');
    await p.context().close();
  }

  console.log('\n=== G. Mode gelap dan lebar 768/1280 ===');
  for (const [lebar, tinggi, skema] of [[375, 812, 'dark'], [768, 1024, 'light'], [1280, 800, 'light'], [1280, 800, 'dark']]) {
    const nama = `${lebar}-${skema === 'dark' ? 'gelap' : 'terang'}`;
    const p = await masuk('sbx_admin', { lebar, tinggi, skema });
    await kartu(p).waitFor({ timeout: 30000 });
    await p.waitForTimeout(2000);
    const latar = await p.evaluate(() => {
      const k = document.querySelector('[data-testid="kartu-identitas"]');
      let el = k.parentElement; let warna = '';
      while (el && !warna) { const w = getComputedStyle(el).backgroundColor; if (w && w !== 'rgba(0, 0, 0, 0)') warna = w; el = el.parentElement; }
      return { latar: warna, kartu: getComputedStyle(k).backgroundColor };
    });
    const harap = skema === 'dark' ? { latar: 'rgb(13, 23, 17)', kartu: 'rgb(22, 63, 45)' } : { latar: 'rgb(246, 244, 238)', kartu: 'rgb(23, 77, 54)' };
    ok(latar.latar === harap.latar && latar.kartu === harap.kartu, `Beranda ${nama}: latar ${latar.latar}, kartu sorot ${latar.kartu} (token K-1)`);
    await tanpaLuapan(p, `Beranda ${nama}`);
    await foto(p, `beranda-sbx_admin-${nama}`);
    await p.goto(app + '/profil');
    await p.getByText('Perangkat & push', { exact: false }).first().waitFor({ timeout: 20000 });
    await p.waitForTimeout(1200);
    await tanpaLuapan(p, `Profil ${nama}`);
    await foto(p, `profil-sbx_admin-${nama}`);
    await p.goto(app + '/akun/edit-profil');
    await p.getByText('Diatur admin (hanya baca)', { exact: false }).first().waitFor({ timeout: 20000 });
    await tanpaLuapan(p, `Edit profil ${nama}`);
    await foto(p, `edit-profil-sbx_admin-${nama}`);
    await p.goto(app + '/akun/ganti-password');
    await p.getByText('Ulangi password baru', { exact: false }).first().waitFor({ timeout: 20000 });
    await tanpaLuapan(p, `Ganti password ${nama}`);
    await foto(p, `ganti-password-sbx_admin-${nama}`);
    await p.context().close();
  }
  {
    const p = await konteks({ skema: 'dark' });
    await p.goto(app + '/login');
    await p.getByPlaceholder('Masukkan username').waitFor({ timeout: 60000 });
    await foto(p, 'masuk-375-gelap');
    await p.context().close();
    const q = await konteks();
    await q.goto(app + '/login');
    await q.getByPlaceholder('Masukkan username').waitFor({ timeout: 60000 });
    await foto(q, 'masuk-375-terang');
    await q.context().close();
  }

  console.log('\n=== H. Pratinjau komponen fondasi (rute sementara) ===');
  for (const skema of ['light', 'dark']) {
    const p = await masuk('sbx_admin', { skema });
    await kartu(p).waitFor();
    await p.goto(app + '/pratinjau-redesain-v2');
    const ada = await p.getByRole('heading', { name: 'Pratinjau fondasi', exact: true }).waitFor({ timeout: 15000 }).then(() => true, () => false);
    if (!ada) { console.log('[lewati] rute pratinjau sementara tidak dipasang'); lewati++; await p.context().close(); break; }
    await p.waitForTimeout(1500);
    await foto(p, `pratinjau-375-${skema === 'dark' ? 'gelap' : 'terang'}`);
    if (skema === 'dark') { await p.context().close(); continue; }
    const catatan = () => p.getByTestId('catatan').innerText();
    // Kepala stack + menu ⋯ → lembar bawah.
    await p.getByRole('button', { name: 'Tindakan lain', exact: true }).click();
    await p.getByRole('button', { name: 'Bagikan', exact: true }).click();
    ok((await catatan()).includes('menu: Bagikan'), 'ScreenHeader stack: ⋯ membuka lembar tindakan dan menjalankan pilihan');
    // SearchBar + lembar penyaring.
    await p.getByLabel('Cari', { exact: true }).fill('ahmad');
    ok((await p.getByTestId('cari').innerText()).includes('ahmad'), 'SearchBar: ketikan diteruskan');
    await p.getByRole('button', { name: 'Saring daftar', exact: true }).click();
    await p.getByRole('radio', { name: 'Unit putri' }).click();
    await p.getByRole('button', { name: 'Terapkan', exact: true }).click();
    ok((await catatan()).includes('saring: putri') && await p.getByRole('button', { name: 'Saring daftar, ada saringan aktif', exact: true }).count() === 1, 'SearchBar: tombol penyaring membuka lembar bawah; saringan aktif ditandai');
    // FilterPills.
    await p.getByRole('radio', { name: 'Disetujui' }).click();
    const terpilih = await p.getByRole('radio', { name: 'Disetujui' }).getAttribute('aria-checked');
    ok((await p.getByTestId('pil').innerText()).includes('setuju') && terpilih === 'true', 'FilterPills: pil terpilih berganti dan diumumkan (aria-checked)');
    // SegmentTabs.
    await p.getByRole('tab', { name: 'Tabungan' }).click();
    ok((await p.getByTestId('tab').innerText()).includes('tabungan') && await p.getByRole('tab', { name: 'Tabungan' }).getAttribute('aria-selected') === 'true', 'SegmentTabs: tab terpilih berganti (aria-selected)');
    // EntityRow, StatusPill, AvatarInisial.
    await p.getByRole('button', { name: /^Ahmad Fauzi Rahman, NIS 2026001/ }).click();
    ok((await catatan()).includes('baris: Ahmad'), 'EntityRow: seluruh baris satu tombol berlabel judul + subjudul + nilai');
    const t = await teks(p);
    ok(t.includes('AR') && t.includes('Menunggu') && t.includes('Tunggakan') && t.includes('Disahkan'), 'AvatarInisial melewati kata berangka ("01 Ahmad … Rahman" → AR); StatusPill berteks');
    const pilIkon = await p.evaluate(() => [...document.querySelectorAll('div')].filter(d => d.children.length === 2 && ['Menunggu', 'Tunggakan', 'Disahkan', 'Aktif', 'Internal', 'Rujukan'].includes(d.innerText.trim())).every(d => d.querySelector('img') !== null));
    ok(pilIkon, 'StatusPill selalu ikon + teks');
    // InfoRow, ProfileHeader, ReceiptCard, StatGrid.
    ok(await p.getByLabel('Username: @contoh, hanya dapat diubah admin', { exact: true }).count() === 1 && await p.getByLabel('Saldo: Rp 300.000', { exact: true }).count() >= 1 && await p.getByLabel('Nominal: Rp 300.000', { exact: true }).count() === 1,
      'InfoRow terkunci, strip ProfileHeader, dan baris ReceiptCard terbaca sebagai "label: nilai"');
    const stat = await p.evaluate(() => ['Transfer menunggu verifikasi: 4', 'Tunggakan: 12', 'Santri aktif: 120'].map(l => document.querySelector(`[aria-label="${l}"]`)?.getBoundingClientRect()).map(r => r && ({ x: Math.round(r.x), y: Math.round(r.y) })));
    ok(stat.every(Boolean) && stat[0].y === stat[1].y && stat[0].x < stat[1].x && stat[2].y > stat[0].y, 'StatGrid: 2 kolom, butir ganjil di baris berikutnya');
    // BarChart.
    const batang = p.getByRole('button', { name: 'Izin: 3', exact: true });
    await batang.click();
    ok(await batang.getAttribute('aria-selected') === 'true' || (await p.getByRole('button', { name: 'Hadir: 24', exact: true }).count()) === 1, 'BarChart: batang dapat diketuk dan berlabel "label: nilai"');
    const tinggi = await p.evaluate(() => ['Hadir: 24', 'Izin: 3', 'Alpa: 0'].map(l => { const b = document.querySelector(`[aria-label="${l}"]`); const isi = b.children[1].firstElementChild; return Math.round(isi.getBoundingClientRect().height); }));
    ok(tinggi[0] > tinggi[1] && tinggi[1] > 0 && tinggi[2] === 0, `BarChart: tinggi batang sebanding nilai (${tinggi.join(' > ')}), nol tanpa batang`);
    // Fab dan ActionBar.
    const fab = p.getByRole('button', { name: 'Ajukan izin baru', exact: true });
    const kF = await kotak(fab), kA = await kotak(p.getByRole('button', { name: 'Serahkan', exact: true }));
    ok(kF.height >= 56 && Math.abs(375 - (kF.x + kF.width) - 16) <= 1 && kF.y + kF.height <= kA.y && (await fab.innerText()).includes('Ajukan izin'),
      `Fab: pil ≥ 56 pt, 16 pt dari tepi kanan, di atas bilah bawah, ikon + teks (tinggi ${kF.height})`);
    await fab.click();
    ok((await catatan()).includes('fab'), 'Fab dapat diketuk');
    ok(t.includes('Belum ada data') && t.includes('Memuat contoh…') && t.includes('Data belum dapat ditampilkan'), 'EmptyState/LoadingState/ErrorState tampil');
    await tanpaLuapan(p, 'Pratinjau fondasi');
    await p.context().close();
  }

  console.log('\n=== I. Privasi & jaringan ===');
  ok(luar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${[...new Set(luar)].join(', ') || '—'})`);
  ok(galatHalaman.length === 0, `Tanpa pageerror (${galatHalaman.slice(0, 2).join('; ') || '—'})`);
  const indeks = await (await fetch(app + '/')).text();
  const src = /src="([^"]*\.bundle[^"]*)"/.exec(indeks)?.[1];
  const bundel = src ? await (await fetch(app + src.replace(/&amp;/g, '&'))).text() : '';
  ok(bundel.length > 100000 && !bundel.includes('alhasan.co.id') && bundel.includes(web.replace('http://', '')), `Bundel (${(bundel.length / 1e6).toFixed(1)} MB) tanpa alamat produksi; API = ${web}`);
} catch (e) {
  gagal++;
  console.log(`[gagal] Pengecualian: ${e.message.split('\n')[0]}`);
  let n = 0;
  for (const c of browser.contexts()) for (const pg of c.pages()) {
    console.log(`  halaman terbuka: ${pg.url()}`);
    await pg.screenshot({ path: path.join(out, `galat-${n++}.png`), fullPage: true }).catch(() => {});
  }
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus / ${gagal} gagal${lewati ? ` / ${lewati} dilewati` : ''}`);
process.exit(gagal === 0 ? 0 : 1);
