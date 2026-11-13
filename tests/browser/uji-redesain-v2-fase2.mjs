import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 — Fase 2 (pengajian, perizinan, izin
// melalui pembimbing, notifikasi) di Expo WEB (Chromium) melawan API/DB uji
// nyata. Ini bukan perangkat native; skala teks sistem, papan ketik, dan bilah
// tab sistem diuji di emulator/simulator.
//
// Dua bagian, masing-masing dengan DB sendiri pada port API yang sama (alamat
// API tertanam di bundel Expo):
//
//   pengajian  JKG_DB=<db> bash tests/jadwal_kelas_gabungan_f1_db.sh
//              JKG_DB=<db> php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
//   izin       V7F4_DB=<db> bash tests/v7_phase4_db.sh
//
//   DB_NAME=<db> PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 CI=1 npx expo start --web --port 8962 --clear &)
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8962 SEED_JSON=ids.json OUT_DIR=… node uji-redesain-v2-fase2.mjs pengajian
//   RTV2_WEB=… RTV2_APP=… OUT_DIR=… node uji-redesain-v2-fase2.mjs izin
//
//   pengajian
//     P-A  Jadwal: ← ke Beranda di atas judul besar, pil tampilan, kartu sesi
//          (seluruh kelas gabungan, pil status, penanda Absen)
//     P-B  Rincian tugas: kepala stack, ← ke halaman sebelumnya / Beranda bila tanpa riwayat
//     P-C  Absensi: "Perbarui daftar" di ⋯, ringkasan mini ikut draf, cari + tab,
//          payload tetap seluruh peserta walau tersaring
//     P-D  Laporan: pil rentang, angka = API, Cetak/Bagikan di ⋯
//     P-E  Rincian laporan: angka per status = API
//   izin
//     I-A  Perizinan: ← ke Beranda, SearchBar di atas pil, baris entitas, FAB K-8
//          (ukuran, letak, tidak menutupi baris terakhir/paginasi, hilang saat
//          kotak cari aktif, syarat tampil per peran = capability server)
//     I-B  Buat izin: langkah, ← per langkah, penghitung, panel "Pengajuan terkirim"
//     I-C  Rincian izin: tindakan dari `aksi` server; keputusan tanpa alasan ditolak server
//     I-D  Laporan izin: angka = API, tiga tombol dokumen
//     I-E  Izin melalui pembimbing: FAB wali, garis waktu tahap, tanpa FAB untuk petugas
//     I-F  Notifikasi: pil, ⋯, rincian, perangkat
//   keduanya
//     K    K-10: setiap rute fase punya tepat satu ← ≥ 44 pt; dibuka langsung → Beranda
//     L    375/768/1280 dan mode gelap tanpa luapan isi
//     Z    Nol permintaan ke luar 127.0.0.1; tanpa pageerror
const bagian = process.argv[2];
const web = process.env.RTV2_WEB, app = process.env.RTV2_APP;
if (!['pengajian', 'izin'].includes(bagian) || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) throw Error('Pemakaian: <pengajian|izin>, hanya 127.0.0.1');
const out = process.env.OUT_DIR || '/tmp/rtv2-f2';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const luar = [], galatHalaman = [];

const tokens = {};
async function api(user, p, body, method) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-rtv2-f2' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, {
    method: method ?? (body ? 'POST' : 'GET'), headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json().catch(() => null) };
}

const browser = await chromium.launch({ headless: true });
/** `tangkap`: daftar yang diisi {method, path, query, body} setiap permintaan API. */
async function masuk(user, { lebar = 375, tinggi = 812, skema = 'light', tangkap } = {}) {
  const ctx = await browser.newContext({ viewport: { width: lebar, height: tinggi }, colorScheme: skema, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  page.on('pageerror', e => galatHalaman.push(`${user}: ${e.message}`));
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (tangkap) {
      let body = null;
      try { body = req.postDataJSON(); } catch { /* bukan JSON */ }
      tangkap.push({ method: req.method(), path: u.pathname.replace('/api/v1', ''), query: Object.fromEntries(u.searchParams), body });
    }
    try {
      const response = await route.fetch();
      await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { /* konteks ditutup */ }
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 60000 });
  await page.waitForTimeout(1500);
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const jalur = p => new URL(p.url()).pathname;
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const foto = (p, nama) => p.screenshot({ path: path.join(out, `${nama}.png`), fullPage: false });
/** Hanya elemen yang terlihat: layar tab/stack lain tetap ada di DOM (tersembunyi). */
const terlihat = l => l.filter({ visible: true });
const kotak = async l => (await terlihat(l).first().boundingBox()) ?? { x: -1, y: -1, width: 0, height: 0 };
const kembali = p => terlihat(p.getByRole('button', { name: /^Kembali/ }));
const judul = (p, nama) => terlihat(p.getByRole('heading', { name: nama, exact: true }));
/**
 * Seluruh layar termasuk bilah tab harus muat, kecuali isi wadah yang
 * sengaja digeser ke samping (FilterPills).
 */
async function tanpaLuapan(p, nama) {
  const peluap = await p.evaluate(() => {
    return [...document.querySelectorAll('body *')]
      .filter(el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().right > innerWidth + 1)
      .filter(el => { for (let a = el.parentElement; a; a = a.parentElement) if (['auto', 'scroll', 'hidden'].includes(getComputedStyle(a).overflowX)) return a.getBoundingClientRect().right > innerWidth + 1; return true; })
      .map(el => (el.innerText || el.tagName).slice(0, 30));
  });
  ok(peluap.length === 0, `${nama}: isi tanpa luapan horizontal${peluap.length ? ` (peluap: ${peluap.slice(0, 3).join(' | ')})` : ''}`);
}
/**
 * K-10 untuk layar stack: tepat satu ← terlihat, ≥ 44 pt. `kembaliKe` = jalur
 * tujuan ← (dibuka langsung tanpa riwayat → Beranda).
 */
async function periksaKembali(p, nama, kembaliKe = '/') {
  const k = kembali(p);
  const b = await kotak(k);
  ok(await k.count() === 1 && b.width >= 44 && b.height >= 44, `${nama}: tepat satu ← terlihat, target ${Math.round(b.width)}×${Math.round(b.height)} ≥ 44`);
  await k.first().click();
  await p.waitForURL(u => u.pathname === kembaliKe, { timeout: 15000 }).catch(() => {});
  ok(jalur(p) === kembaliKe, `${nama}: ← membuka ${kembaliKe} (kini ${jalur(p)})`);
}
/** Geometri FAB di layar web: 56 pt dan jarak 16 pt dari tepi kanan dan bilah bawah. */
async function periksaFab(p, nama, label) {
  const fab = terlihat(p.getByRole('button', { name: label, exact: true }));
  const b = await kotak(fab);
  const vp = p.viewportSize();
  const bilah = p.getByTestId('bilah-tab-web');
  const tab = await bilah.count() ? await bilah.boundingBox() : null;
  const batasBawah = tab?.y ?? vp.height;
  const isiTeks = (await fab.first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
  ok(await fab.count() === 1 && isiTeks === 'Ajukan izin', `${nama}: FAB "${label}" berteks "Ajukan izin" (ikon + teks), tepat satu`);
  ok(b.height >= 56 && b.height <= 60, `${nama}: tinggi FAB ${Math.round(b.height)} pt (≥ 56)`);
  ok(Math.abs(vp.width - (b.x + b.width) - 16) <= 1.5 && Math.abs(batasBawah - (b.y + b.height) - 16) <= 1.5,
    `${nama}: FAB 16 pt dari kanan (${Math.round(vp.width - b.x - b.width)}) dan 16 pt dari batas bawah isi (${Math.round(vp.height - b.y - b.height)})`);
  ok(b.width <= vp.width / 2, `${nama}: lebar FAB ${Math.round(b.width)} ≤ setengah layar (${vp.width / 2})`);
  return { fab, b };
}
/** Gulir daftar sampai habis; elemen terakhir yang dapat diketuk harus berada di atas FAB. */
async function fabTidakMenutupi(p, nama, label, terakhir) {
  const baris = terlihat(terakhir).last();
  await baris.scrollIntoViewIfNeeded();
  await baris.evaluate(el => {
    for (let parent = el.parentElement; parent; parent = parent.parentElement) {
      if (['auto', 'scroll'].includes(getComputedStyle(parent).overflowY)) {
        parent.scrollTop = parent.scrollHeight;
        break;
      }
    }
  });
  await p.waitForTimeout(600);
  const fab = await kotak(p.getByRole('button', { name: label, exact: true }));
  const t = (await baris.boundingBox()) ?? { y: 9999, height: 0, x: 0, width: 0 };
  ok(t.y >= 0 && t.y + t.height <= fab.y, `${nama}: di ujung daftar, elemen terakhir (bawah ${Math.round(t.y + t.height)}) berada di atas FAB (atas ${Math.round(fab.y)})`);
  const tengah = await p.evaluate(([x, y]) => document.elementFromPoint(x, y)?.closest('[role="button"],button')?.getAttribute('aria-label') ?? document.elementFromPoint(x, y)?.textContent?.slice(0, 40) ?? '', [t.x + t.width / 2, t.y + t.height / 2]);
  ok(!/Ajukan izin/.test(tengah), `${nama}: titik tengah elemen terakhir tidak tertutup FAB (${tengah.slice(0, 30)})`);
}

try {
  if (bagian === 'pengajian') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    const T = ids.tanggal, JG = ids.jadwal_gabungan, JS = ids.jadwal_satu, sfx = ids.sfx;
    const G = 'sbx_guru_biasa';
    const apiJadwal = (await api(G, `/schedules/${JG}?date=${T}`)).json.data;
    const kelas = apiJadwal.classes.map(c => c.name);

    console.log('\n=== P-A. Jadwal (P-Akar) ===');
    const tangkap = [];
    const g = await masuk(G, { tangkap });
    await g.goto(app + '/schedules');
    await tunggu(g, `Nahwu ${sfx}`);
    {
      const k = terlihat(g.getByRole('button', { name: 'Kembali ke Beranda', exact: true }));
      const bk = await kotak(k), bj = await kotak(judul(g, 'Jadwal'));
      ok(await k.count() === 1 && bk.width >= 44 && bk.height >= 44 && bk.y + bk.height <= bj.y + 1, 'Jadwal: ← "Kembali ke Beranda" ≥ 44 pt di atas judul besar');
      const t = await teks(g);
      ok(t.includes('Sesi mengajar pekan ini'), 'Jadwal: subjudul penjelas');
      ok(await terlihat(g.getByRole('radio', { name: 'Per pekan' })).getAttribute('aria-checked') === 'true' && await terlihat(g.getByRole('radio', { name: 'Rentang kustom' })).count() === 1, 'Jadwal: pil Per pekan (terpilih) / Rentang kustom');
      ok(await terlihat(g.getByRole('button', { name: /hari ini$/ })).getAttribute('aria-selected') === 'true' || (await terlihat(g.getByRole('button', { name: /hari ini$/ })).count()) === 1, 'Jadwal: WeekStrip menandai hari ini');
      const tanggalPekan = g.getByRole('button', { name: /^(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),? \d+ / });
      const ukuranTanggal = await tanggalPekan.evaluateAll((items) => items.map((item) => {
        const { width, height } = item.getBoundingClientRect();
        return { width, height };
      }));
      ok(ukuranTanggal.length === 7 && ukuranTanggal.every(({ width, height }) => width >= 44 && height >= 44),
        `Jadwal 375: tujuh target tanggal ≥ 44 × 44 (${ukuranTanggal.map(({ width }) => width.toFixed(1)).join(', ')} pt)`);
      const kartu = terlihat(g.getByRole('button', { name: new RegExp(`^Nahwu ${sfx}, `) }));
      const tk = (await kartu.first().innerText()).replace(/\s+/g, ' ');
      ok(kelas.every(n => tk.includes(n)), `Jadwal: kartu sesi gabungan memuat seluruh kelas (${kelas.length})`);
      ok(tk.includes('Belum diabsen') && tk.includes('Absen') && tk.includes(apiJadwal.start_time), 'Jadwal: kartu berpil status (ikon + teks), blok jam, dan penanda Absen');
      ok(await terlihat(g.getByRole('button', { name: /^Notifikasi/ })).count() === 1, 'Jadwal: lonceng notifikasi tetap tersedia');
      await tanpaLuapan(g, 'Jadwal 375');
      await foto(g, 'jadwal-375-terang');
      await g.getByRole('radio', { name: 'Rentang kustom' }).click();
      await tunggu(g, 'Rentang tanggal');
      ok((await teks(g)).includes('Sesi mengajar pada rentang tanggal'), 'Jadwal: rentang kustom tetap tersedia');
      await g.getByRole('radio', { name: 'Per pekan' }).click();
      await k.first().click();
      await g.waitForURL(u => u.pathname === '/', { timeout: 15000 }).catch(() => {});
      ok(jalur(g) === '/', 'Jadwal: ← membuka Beranda');
    }

    console.log('\n=== P-B. Rincian tugas (P-Rincian) ===');
    await g.goto(app + '/schedules');
    await tunggu(g, `Nahwu ${sfx}`);
    await terlihat(g.getByRole('button', { name: new RegExp(`^Nahwu ${sfx}, `) })).first().click();
    await tombol(g, 'Buka pertemuan').waitFor({ timeout: 20000 });
    {
      ok(await judul(g, 'Rincian tugas').count() === 1, 'Rincian tugas: satu judul kepala stack (kepala native tidak ganda)');
      const t = await teks(g);
      ok(t.includes('Kelas gabungan') && kelas.every(n => t.includes(n)) && t.includes('Belum dibuka') && t.includes(apiJadwal.teacher.name) && t.includes(apiJadwal.place), 'Rincian tugas: kartu kepala, pil status, baris Guru/Kelas/Kitab/Tempat');
      await tanpaLuapan(g, 'Rincian tugas 375');
      await foto(g, 'rincian-tugas-375-terang');
      const k = kembali(g);
      ok(await k.count() === 1, 'Rincian tugas: tepat satu ←');
      await k.first().click();
      await g.waitForURL(u => u.pathname === '/schedules', { timeout: 15000 }).catch(() => {});
      ok(jalur(g) === '/schedules', 'Rincian tugas: ← kembali ke halaman sebelumnya (Jadwal)');
    }

    console.log('\n=== P-C. Absensi pertemuan ===');
    await g.goto(app + `/schedule/${JG}?date=${T}`);
    await tombol(g, 'Buka pertemuan').click();
    await tunggu(g, 'Santri · 5 orang');
    const MG = Number(jalur(g).split('/').pop());
    {
      ok(await judul(g, 'Absensi pertemuan').count() === 1, 'Absensi: kepala stack');
      ok(await terlihat(tombol(g, 'Perbarui daftar')).count() === 0 && await terlihat(tombol(g, 'Tindakan lain')).count() === 1, 'Absensi: "Perbarui daftar" tidak lagi tombol langsung; ⋯ tersedia');
      const label = n => terlihat(g.getByLabel(n, { exact: true })).count();
      ok(await label('Hadir: 5') === 1 && await label('Sakit: 0') === 1 && await label('Alpa: 0') === 1, 'Absensi: ringkasan mini Hadir/Izin/Terlambat/Sakit/Alpa');
      const radio = terlihat(g.getByRole('radio', { name: 'Sakit' })).nth(1);
      const br = await radio.boundingBox();
      ok(br.height >= 44 && br.width >= 44, `Absensi: tombol status santri ${Math.round(br.width)}×${Math.round(br.height)} ≥ 44`);
      ok((await radio.innerText()).trim() === 'S' && await radio.getAttribute('aria-label') === 'Sakit', 'Absensi: pemilih status ikon + huruf, nama status lengkap sebagai label');
      await radio.click();
      ok(await label('Hadir: 4') === 1 && await label('Sakit: 1') === 1, 'Absensi: ringkasan mini mengikuti draf');
      // Saringan tampilan tidak mengubah ringkasan maupun payload.
      await g.getByPlaceholder('Cari nama atau NIS').fill('zzz-tidak-ada');
      await tunggu(g, 'Tidak ada santri yang cocok');
      ok(await label('Hadir: 4') === 1 && (await teks(g)).includes('Santri · 5 orang'), 'Absensi: saat tersaring, ringkasan dan jumlah tetap seluruh peserta');
      ok(await terlihat(g.getByRole('tab')).count() === 3, 'Absensi: tab kelas asal (Semua/PA/PI) tetap ada');
      // ⋯ → Perbarui daftar: permintaan sama, isian guru tetap.
      tangkap.length = 0;
      await tombol(g, 'Tindakan lain').click();
      await terlihat(tombol(g, 'Perbarui daftar')).click();
      await tunggu(g, 'Daftar santri sudah sesuai dengan penempatan kelas terbaru.');
      const segar = tangkap.filter(r => r.method === 'POST');
      ok(segar.length === 1 && segar[0].path === `/meetings/${MG}/participants/refresh`, '⋯ → "Perbarui daftar": tepat satu POST participants/refresh');
      ok(await label('Sakit: 1') === 1, 'Perbarui daftar: isian guru (Sakit) dipertahankan');
      await foto(g, 'absensi-375-terang');
      await tanpaLuapan(g, 'Absensi 375');
      tangkap.length = 0;
      await tombol(g, 'Konfirmasi dan kirim').click();
      await tunggu(g, 'Absensi berhasil disimpan');
      const put = tangkap.filter(r => r.method === 'PUT');
      ok(put.length === 1 && put[0].body.students.length === 5 && put[0].body.students.filter(s => s.status === 'Sakit').length === 1 && typeof put[0].body.idempotency_key === 'string',
        'Kirim saat tersaring: satu PUT berkunci idempotensi, payload seluruh 5 peserta (1 Sakit)');
      ok((await teks(g)).includes('Absensi tersimpan'), 'P-Sukses ringkas: panel "Absensi tersimpan"');
      await g.getByPlaceholder('Cari nama atau NIS').fill('');
      ok(await terlihat(tombol(g, 'Tindakan lain')).count() === 0, 'Pertemuan Selesai: ⋯ (Perbarui daftar) tidak ditawarkan');
    }

    console.log('\n=== P-D. Laporan kehadiran (P-Laporan) ===');
    tangkap.length = 0;
    await g.goto(app + '/reports');
    await tunggu(g, 'Ringkasan jadwal');
    {
      const awal = tangkap.find(r => r.path === '/reports');
      const lap = (await api(G, `/reports?date_from=${awal.query.date_from}&date_to=${awal.query.date_to}&page=1&per_page=25`)).json.data;
      const persen = `${Math.round((lap.summary.statuses.Hadir / lap.summary.detail_count) * 100)}%`;
      const t = await teks(g);
      ok(await judul(g, 'Laporan kehadiran').count() === 1 && t.includes('Persentase hadir') && t.includes(persen), `Laporan: kartu sorot "Persentase hadir" = ringkasan server (${persen})`);
      ok(t.includes(`${lap.summary.statuses.Hadir} hadir dari ${lap.summary.detail_count} baris kehadiran · ${lap.summary.meeting_count} pertemuan`), 'Laporan: keterangan angka = API, tanpa pembanding');
      ok(await terlihat(g.getByRole('radio', { name: 'Bulan ini' })).getAttribute('aria-checked') === 'true', 'Laporan: pil rentang, bawaan "Bulan ini" (permintaan awal tidak berubah)');
      for (const s of ['Hadir', 'Izin', 'Terlambat', 'Sakit', 'Alpa']) {
        if (await terlihat(g.getByRole('button', { name: `${s}: ${lap.summary.statuses[s]}`, exact: true })).count() !== 1) ok(false, `Laporan: batang ${s} = ${lap.summary.statuses[s]}`);
      }
      ok(true, 'Laporan: grafik batang per status berlabel nilai API');
      ok(await terlihat(tombol(g, 'Cetak PDF')).count() === 0, 'Laporan: Cetak tidak lagi tombol di badan');
      await tombol(g, 'Tindakan lain').click();
      ok(await terlihat(tombol(g, 'Cetak PDF')).count() === 1 && await terlihat(tombol(g, 'Simpan PDF')).count() === 1, 'Laporan: ⋯ memuat Cetak PDF dan Simpan PDF');
      await terlihat(tombol(g, 'Tutup')).last().click();
      tangkap.length = 0;
      await g.getByRole('radio', { name: '7 hari' }).click();
      await g.waitForTimeout(1500);
      const r7 = tangkap.filter(r => r.path === '/reports').pop();
      const d = new Date(`${awal.query.date_to}T00:00:00`); d.setDate(d.getDate() - 6);
      const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      ok(Boolean(r7) && r7.query.date_from === iso && r7.query.date_to === awal.query.date_to && JSON.stringify(Object.keys(r7.query).sort()) === JSON.stringify(Object.keys(awal.query).sort()),
        `Pil "7 hari": endpoint dan nama parameter sama, hanya nilai tanggal (${iso})`);
      await tombol(g, 'Buka filter').click();
      ok(await terlihat(g.getByRole('heading', { name: 'Filter laporan saya' })).count() === 1 && await terlihat(tombol(g, 'Terapkan filter')).count() === 1, 'Laporan: penyaring membuka lembar bawah');
      await terlihat(tombol(g, 'Tutup')).last().click();
      await g.getByRole('radio', { name: 'Bulan ini' }).click();
      await g.waitForTimeout(1200);
      await tanpaLuapan(g, 'Laporan 375');
      await foto(g, 'laporan-375-terang');
    }

    console.log('\n=== P-E. Rincian laporan pertemuan ===');
    await terlihat(g.getByRole('button', { name: /^Buka detail pertemuan / })).first().click();
    await tunggu(g, 'Peserta (5/5)');
    {
      const rinci = (await api(G, `/reports/meetings/${MG}`)).json.data;
      ok(await judul(g, 'Rincian pertemuan').count() === 1, 'Rincian laporan: kepala stack');
      let cocok = true;
      for (const s of ['Hadir', 'Izin', 'Terlambat', 'Sakit', 'Alpa']) cocok &&= await terlihat(g.getByLabel(`${s}: ${rinci.student_summary.statuses[s] ?? 0}`, { exact: true })).count() === 1;
      ok(cocok, 'Rincian laporan: angka per status = ringkasan server');
      ok(await terlihat(g.getByRole('tab')).count() === 3 && await terlihat(g.getByPlaceholder('Cari nama atau NIS')).count() === 1 && await g.getByRole('radiogroup').filter({ visible: true }).count() === 0, 'Rincian laporan: cari + tab kelas asal, hanya baca');
      await tanpaLuapan(g, 'Rincian laporan 375');
      await foto(g, 'rincian-laporan-375-terang');
      await kembali(g).first().click();
      await g.waitForURL(u => u.pathname === '/reports', { timeout: 15000 }).catch(() => {});
      ok(jalur(g) === '/reports', 'Rincian laporan: ← kembali ke Laporan');
    }

    console.log('\n=== K. K-10: rute dibuka langsung (tanpa riwayat) → ← membuka Beranda ===');
    for (const [rute, tanda, nama] of [
      [`/schedule/${JS}?date=${T}`, 'Buka pertemuan', 'Rincian tugas'],
      [`/meeting/${MG}`, 'Santri · 5 orang', 'Absensi pertemuan'],
      ['/reports', 'Ringkasan jadwal', 'Laporan kehadiran'],
      [`/report/${MG}`, 'Peserta (5/5)', 'Rincian laporan'],
      ['/meeting/99999999', 'Data belum dapat ditampilkan', 'Absensi yang ditolak server'],
    ]) {
      await g.goto(app + rute);
      await tunggu(g, tanda);
      await periksaKembali(g, `${nama} dibuka langsung`);
    }

    console.log('\n=== L. Mode gelap dan lebar 768/1280 ===');
    for (const [lebar, tinggi, skema] of [[375, 812, 'dark'], [768, 1024, 'light'], [1280, 800, 'dark']]) {
      const p = await masuk(G, { lebar, tinggi, skema });
      const nama = `${lebar}-${skema === 'dark' ? 'gelap' : 'terang'}`;
      for (const [rute, tanda, label] of [['/schedules', `Nahwu ${sfx}`, 'jadwal'], [`/meeting/${MG}`, 'Santri · 5 orang', 'absensi'], ['/reports', 'Ringkasan jadwal', 'laporan'], [`/report/${MG}`, 'Peserta (5/5)', 'rincian-laporan']]) {
        await p.goto(app + rute);
        await tunggu(p, tanda);
        await p.waitForTimeout(500);
        await tanpaLuapan(p, `${label} ${nama}`);
        await foto(p, `${label}-${nama}`);
      }
      await p.context().close();
    }
    await g.context().close();
  }

  if (bagian === 'izin') {
    const PG = 'sbx_pengurus_a', AD = 'sbx_admin', MU = 'sbx_murobi_a', OT = 'sbx_ortu_a', WA = 'sbx_v7_wali_a', PB = 'sbx_v7_pembimbing_satu';
    const kemampuan = async u => (await api(u, '/profile')).json.data.capabilities;

    console.log('\n=== I-A. Perizinan: P-Akar + P-Daftar + FAB (K-8) ===');
    const tangkap = [];
    const pg = await masuk(PG, { tangkap });
    await pg.goto(app + '/perizinan');
    await tombol(pg, 'Ajukan izin baru').waitFor({ timeout: 20000 });
    await pg.waitForTimeout(800);
    {
      const k = terlihat(pg.getByRole('button', { name: 'Kembali ke Beranda', exact: true }));
      const bk = await kotak(k), bj = await kotak(judul(pg, 'Perizinan'));
      ok(await k.count() === 1 && bk.width >= 44 && bk.height >= 44 && bk.y + bk.height <= bj.y + 1, 'Perizinan: ← "Kembali ke Beranda" ≥ 44 pt di atas judul besar');
      const cari = await kotak(pg.getByPlaceholder('Cari santri, NIS, atau alasan')), pil = await kotak(pg.getByRole('radio', { name: /^Perlu tindakan/ }));
      ok(cari.y > bj.y && cari.y + cari.height <= pil.y, 'Perizinan: SearchBar di bawah judul dan di atas pil penyaring');
      for (const n of [/^Perlu tindakan/, 'Semua', 'Disetujui', 'Ditolak']) if (await terlihat(pg.getByRole('radio', { name: n, exact: typeof n === 'string' })).count() !== 1) ok(false, `Perizinan: pil ${n}`);
      ok(await terlihat(pg.getByRole('radio', { name: /^Perlu tindakan/ })).getAttribute('aria-checked') === 'true', 'Perizinan: FilterPills Perlu tindakan (terpilih) / Semua / Disetujui / Ditolak');
      await pg.getByRole('radio', { name: 'Semua', exact: true }).click();
      await terlihat(pg.getByRole('button', { name: /^Pengajuan \d+ untuk / })).first().waitFor({ timeout: 20000 });
      const baris = (await terlihat(pg.getByRole('button', { name: /^Pengajuan \d+ untuk / })).first().innerText()).replace(/\s+/g, ' ');
      ok(/NIS /.test(baris) && /#\d+/.test(baris) && /(Diajukan|Disetujui|Ditolak|Dibatalkan|Perlu Penetapan Admin)/.test(baris), 'Perizinan: baris entitas (avatar, nama, NIS · nomor, rentang, pil status)');
      const { fab } = await periksaFab(pg, 'Perizinan 375', 'Ajukan izin baru');
      ok(await terlihat(tombol(pg, 'Ajukan izin baru')).count() === 1, 'Perizinan: alternatif tombol atas TIDAK dipakai pada skala teks 1,0 (hanya FAB)');
      await foto(pg, 'perizinan-375-terang');
      // Kotak cari aktif → FAB hilang; lepas fokus → kembali.
      await pg.getByPlaceholder('Cari santri, NIS, atau alasan').focus();
      await pg.waitForTimeout(300);
      ok(await terlihat(tombol(pg, 'Ajukan izin baru')).count() === 0, 'Perizinan: FAB disembunyikan saat kotak cari aktif (papan ketik)');
      await foto(pg, 'perizinan-375-cari-aktif');
      await pg.getByPlaceholder('Cari santri, NIS, atau alasan').blur();
      await pg.waitForTimeout(300);
      ok(await terlihat(tombol(pg, 'Ajukan izin baru')).count() === 1, 'Perizinan: FAB kembali saat kotak cari tidak aktif');
      await fabTidakMenutupi(pg, 'Perizinan pengurus', 'Ajukan izin baru', pg.getByRole('button', { name: /^(Pengajuan \d+ untuk |Berikutnya$)/ }));
      await foto(pg, 'perizinan-375-ujung-daftar');
      await tanpaLuapan(pg, 'Perizinan 375');
      await fab.first().click();
      await pg.waitForURL(u => u.pathname === '/izin/buat', { timeout: 15000 }).catch(() => {});
      ok(jalur(pg) === '/izin/buat', 'FAB membuka formulir pengajuan');
    }
    {
      // Admin: daftar berhalaman → tombol paginasi tidak tertutup FAB.
      const ad = await masuk(AD);
      const kap = await kemampuan(AD);
      await ad.goto(app + '/perizinan');
      await terlihat(ad.getByRole('radio', { name: 'Semua', exact: true })).waitFor({ timeout: 20000 });
      await ad.getByRole('radio', { name: 'Semua', exact: true }).click();
      await terlihat(tombol(ad, 'Berikutnya')).waitFor({ timeout: 20000 });
      ok(await terlihat(tombol(ad, 'Ajukan izin baru')).count() === (kap.aksi.dapat_membuat_pengajuan ? 1 : 0), `Admin: FAB tampil sesuai capability server (dapat_membuat_pengajuan=${kap.aksi.dapat_membuat_pengajuan})`);
      if (kap.aksi.dapat_membuat_pengajuan) {
        await fabTidakMenutupi(ad, 'Perizinan admin (berhalaman)', 'Ajukan izin baru', tombol(ad, 'Berikutnya'));
        await terlihat(tombol(ad, 'Berikutnya')).click();
        await tunggu(ad, 'Halaman 2 dari');
        ok(true, 'Admin: tombol paginasi "Berikutnya" dapat diketuk di ujung daftar');
        await foto(ad, 'perizinan-admin-375-paginasi');
      }
      await ad.context().close();
      // Syarat tampil per peran = capability server; orang tua tetap tanpa tombol.
      for (const [user, label] of [[MU, 'Murobi'], [OT, 'Orang tua'], [PB, 'Pembimbing V7']]) {
        const p = await masuk(user);
        const c = await kemampuan(user);
        await p.goto(app + '/perizinan');
        await judul(p, 'Perizinan').waitFor({ timeout: 20000 });
        await p.waitForTimeout(2500);
        const harap = c.list.length > 0 && c.aksi.dapat_membuat_pengajuan && c.default_mode !== 'orang_tua' ? 1 : 0;
        ok(await terlihat(tombol(p, 'Ajukan izin baru')).count() === harap, `${label}: FAB ${harap ? 'tampil' : 'tidak tampil'} = capability server`);
        ok(await terlihat(p.getByRole('button', { name: 'Kembali ke Beranda', exact: true })).count() === 1, `${label}: Perizinan ber-←`);
        await p.context().close();
      }
      for (const [lebar, tinggi, skema] of [[768, 1024, 'light'], [1280, 800, 'dark'], [375, 812, 'dark']]) {
        const p = await masuk(PG, { lebar, tinggi, skema });
        await p.goto(app + '/perizinan');
        await tombol(p, 'Ajukan izin baru').waitFor({ timeout: 20000 });
        await p.waitForTimeout(800);
        const nama = `${lebar}-${skema === 'dark' ? 'gelap' : 'terang'}`;
        const { b } = await periksaFab(p, `Perizinan ${nama}`, 'Ajukan izin baru');
        const bilah = await p.getByTestId('bilah-tab-web').boundingBox();
        ok(bilah && b.y + b.height < bilah.y, `Perizinan ${nama}: FAB berada di atas bilah tab web`);
        await tanpaLuapan(p, `Perizinan ${nama}`);
        await foto(p, `perizinan-${nama}`);
        await p.context().close();
      }
    }

    console.log('\n=== I-B. Buat izin (P-Form langkah) ===');
    let idBaru = 0;
    {
      await pg.getByRole('radio').first().waitFor({ timeout: 20000 });
      ok(await judul(pg, 'Ajukan izin').count() === 1, 'Buat izin: kepala stack');
      ok(await terlihat(pg.getByRole('button', { name: 'Kembali ke halaman sebelumnya', exact: true })).count() === 1 && await kembali(pg).count() === 1, 'Langkah 1: ← "Kembali ke halaman sebelumnya" (label khusus: layar ini punya kolom tanggal "Kembali")');
      ok(await terlihat(pg.getByPlaceholder('Cari nama santri atau NIS')).count() === 1, 'Langkah 1: SearchBar + baris santri');
      const santri = terlihat(pg.getByRole('radio', { name: /SBX Santri A1/ })).first();
      await santri.click();
      ok(await santri.getAttribute('aria-checked') === 'true', 'Langkah 1: baris santri terpilih (radio)');
      await tombol(pg, 'Lanjut isi data izin').click();
      await tunggu(pg, 'Rentang izin');
      ok(await terlihat(pg.getByRole('button', { name: 'Kembali ke langkah sebelumnya', exact: true })).count() === 1, 'Langkah 2: ← berlabel "Kembali ke langkah sebelumnya"');
      await terlihat(pg.getByRole('button', { name: 'Kembali ke langkah sebelumnya', exact: true })).click();
      ok(jalur(pg) === '/izin/buat' && await terlihat(tombol(pg, 'Lanjut isi data izin')).count() === 1 && await santri.getAttribute('aria-checked') === 'true', 'K-10: ← kembali ke langkah sebelumnya dulu; pilihan santri tidak hilang');
      await tombol(pg, 'Lanjut isi data izin').click();
      ok((await teks(pg)).includes('SBX Santri A1') && (await teks(pg)).includes('0/500'), 'Langkah 2: kartu santri terpilih di atas, penghitung karakter alasan');
      await pg.getByLabel('Mulai', { exact: true }).fill('2028-01-05');
      await pg.getByLabel('Kembali', { exact: true }).fill('2028-01-06');
      await pg.getByPlaceholder('Minimal 3 karakter').fill('Uji fase dua');
      ok((await teks(pg)).includes('12/500'), 'Penghitung mengikuti isian');
      await foto(pg, 'buat-izin-375-isi');
      await tombol(pg, 'Tinjau pengajuan').click();
      await tunggu(pg, 'Tinjau sebelum mengirim');
      await tanpaLuapan(pg, 'Buat izin 375');
      tangkap.length = 0;
      await tombol(pg, 'Kirim pengajuan').click();
      await tunggu(pg, 'Riwayat perubahan');
      const post = tangkap.filter(r => r.method === 'POST');
      ok(post.length === 1 && post[0].path === '/izin/pengajuan' && typeof post[0].body.idempotency_key === 'string', 'Kirim: tepat satu POST /izin/pengajuan berkunci idempotensi');
      idBaru = Number(jalur(pg).split('/').pop());
      ok((await teks(pg)).includes('Pengajuan terkirim') && idBaru > 0, 'P-Sukses: rincian menampilkan "Pengajuan terkirim"');
    }

    console.log('\n=== I-C. Rincian izin (P-Rincian) ===');
    {
      const t = await teks(pg);
      ok(await judul(pg, 'Rincian izin').count() === 1 && t.includes('SBX Santri A1') && t.includes('Perlu Penetapan Admin') && t.includes('Uji fase dua'), 'Rincian izin: kartu kepala (avatar, nama, pil status), baris Alasan/Pengurus/Murobi');
      const detail = (await api(PG, `/izin/pengajuan/${idBaru}?mode=pengurus`)).json.data;
      ok(detail.aksi.batalkan === true && await terlihat(tombol(pg, 'Batalkan pengajuan')).count() === 1 && await terlihat(tombol(pg, 'Setujui')).count() === 0, 'Pengurus pengaju: hanya "Batalkan pengajuan" (aksi server), tanpa Setujui/Tolak');
      await foto(pg, 'rincian-izin-pengurus-375');
      await tanpaLuapan(pg, 'Rincian izin 375');
      const k = kembali(pg);
      ok(await k.count() === 1, 'Rincian izin: tepat satu ←');
      // Admin menetapkan murobi dari bilah bawah.
      const ad = await masuk(AD);
      await ad.goto(app + `/izin/${idBaru}?mode=admin`);
      await tombol(ad, 'Tetapkan murobi').waitFor({ timeout: 20000 });
      await ad.getByPlaceholder('Wajib diisi').last().fill('Penetapan uji fase dua');
      await tombol(ad, 'Tetapkan murobi').click();
      await tunggu(ad, 'Murobi tujuan ditetapkan');
      const guru = (await api(AD, `/izin/pengajuan/${idBaru}?mode=admin`)).json.data.pengajuan.murobi.guru_id;
      ok(guru > 0, 'Admin: "Tetapkan murobi" (aksi server) tersimpan');
      await ad.context().close();
      // Murobi: keputusan tetap memerlukan alasan — server yang menolak.
      const tm = [];
      const mu = await masuk(MU, { tangkap: tm });
      await mu.goto(app + `/izin/${idBaru}?mode=murobi`);
      await tombol(mu, 'Setujui').waitFor({ timeout: 20000 });
      ok(await terlihat(tombol(mu, 'Tolak')).count() === 1 && await terlihat(tombol(mu, 'Batalkan pengajuan')).count() === 0, 'Murobi: Setujui/Tolak di bilah bawah sesuai aksi server');
      await tombol(mu, 'Tolak').click();
      await judul(mu, 'Tolak pengajuan').waitFor();
      await tombol(mu, 'Tutup').last().click();
      await judul(mu, 'Tolak pengajuan').waitFor({ state: 'hidden' });
      ok(tm.filter(r => r.method === 'POST' && /keputusan$/.test(r.path)).length === 0, 'Membuka dan menutup lembar Tolak tanpa konfirmasi tidak mengirim keputusan');
      await tombol(mu, 'Setujui').click();
      await judul(mu, 'Setujui pengajuan').waitFor();
      await tombol(mu, 'Konfirmasi setujui').click();
      await mu.waitForTimeout(1500);
      const tolak = tm.filter(r => r.method === 'POST' && /keputusan$/.test(r.path));
      const status1 = (await api(MU, `/izin/pengajuan/${idBaru}?mode=murobi`)).json.data.pengajuan.status;
      ok(tolak.length === 1 && status1 === 'Diajukan' && !(await teks(mu)).includes('tersimpan sebagai'), `Setujui tanpa alasan: tidak ada keputusan (status tetap ${status1}; ${tolak.length} permintaan ditolak server)`);
      await foto(mu, 'rincian-izin-murobi-375-tanpa-alasan');
      await mu.getByPlaceholder('Minimal 3 karakter').first().fill('Alasan uji fase dua');
      await tombol(mu, 'Konfirmasi setujui').click();
      await tunggu(mu, 'Keputusan Disetujui tersimpan');
      ok((await api(MU, `/izin/pengajuan/${idBaru}?mode=murobi`)).json.data.pengajuan.status === 'Disetujui', 'Setujui dengan alasan: tersimpan');
      ok(await terlihat(tombol(mu, 'Setujui')).count() === 0, 'Sesudah diputus: bilah keputusan hilang (aksi server)');
      await foto(mu, 'rincian-izin-murobi-375-diputus');
      await mu.context().close();
    }

    console.log('\n=== I-D. Laporan izin (P-Laporan) ===');
    {
      const tl = [];
      const p = await masuk(PG, { tangkap: tl });
      await p.goto(app + '/izin/laporan?mode=pengurus');
      await tunggu(p, 'Durasi keputusan');
      const q = tl.find(r => r.path === '/izin/laporan').query;
      const lap = (await api(PG, `/izin/laporan?${new URLSearchParams(q)}`)).json.data;
      ok(await judul(p, 'Laporan perizinan').count() === 1, 'Laporan izin: kepala stack');
      let cocok = await terlihat(p.getByLabel(`Total: ${lap.ringkasan.total}`, { exact: true })).count() === 1;
      for (const [nama, jumlah] of Object.entries(lap.ringkasan.per_status)) cocok &&= await terlihat(p.getByLabel(`${nama}: ${jumlah}`, { exact: true })).count() === 1;
      ok(cocok, `Laporan izin: kartu angka Total dan per status = API (total ${lap.ringkasan.total})`);
      ok(await terlihat(tombol(p, 'Cetak')).count() === 1 && await terlihat(tombol(p, 'Bagikan PDF')).count() === 1 && await terlihat(tombol(p, 'Bagikan CSV')).count() === 1, 'Laporan izin: Cetak, Bagikan PDF, Bagikan CSV di bilah bawah');
      await tombol(p, 'Filter').click();
      ok(await terlihat(p.getByRole('heading', { name: 'Filter', exact: true })).count() === 1 && await terlihat(tombol(p, 'Terapkan filter')).count() === 1, 'Laporan izin: penyaring tanggal/status di lembar bawah');
      await terlihat(tombol(p, 'Tutup')).last().click();
      await tanpaLuapan(p, 'Laporan izin 375');
      await foto(p, 'laporan-izin-375-terang');
      await periksaKembali(p, 'Laporan izin dibuka langsung');
      await p.context().close();
    }

    console.log('\n=== I-E. Izin melalui pembimbing ===');
    let idWali = 0;
    {
      const w = await masuk(WA);
      await w.goto(app + '/perizinan');
      await w.getByRole('button', { name: 'Buka izin orang tua melalui pembimbing' }).click();
      await tunggu(w, 'Izin anak melalui pembimbing');
      await w.waitForTimeout(800);
      ok(await judul(w, 'Izin melalui pembimbing').count() === 1 && await kembali(w).count() === 1, 'Daftar izin wali: kepala stack ber-←');
      await periksaFab(w, 'Izin wali (orang tua) 375', 'Ajukan izin baru');
      await foto(w, 'izin-wali-daftar-375');
      await tanpaLuapan(w, 'Izin wali daftar 375');
      await tombol(w, 'Ajukan izin baru').click();
      await tunggu(w, 'Rentang izin');
      ok(await judul(w, 'Ajukan izin anak').count() === 1, 'Ajukan izin anak: kepala stack');
      await w.getByRole('radio', { name: 'SBX V7 Santri Satu (V7S0001)' }).click();
      await w.getByLabel('Tanggal izin').fill('2028-02-02');
      await w.getByLabel('Tanggal kembali').fill('2028-02-03');
      await w.getByLabel('Alasan izin').fill('Uji fase dua wali');
      await tombol(w, 'Tinjau pengajuan').click();
      await tunggu(w, 'Tinjau sebelum mengirim');
      await foto(w, 'izin-wali-buat-375-tinjau');
      await kembali(w).first().click();
      ok(jalur(w) === '/izin-wali/buat' && await terlihat(tombol(w, 'Tinjau pengajuan')).count() === 1 && await w.getByLabel('Alasan izin').inputValue() === 'Uji fase dua wali', 'K-10: ← dari Tinjau kembali ke isian; draf tidak hilang');
      await tombol(w, 'Tinjau pengajuan').click();
      await tombol(w, 'Kirim pengajuan').click();
      await tunggu(w, 'Menunggu tinjauan pembimbing');
      idWali = Number(jalur(w).split('/').pop());
      const t = await teks(w);
      ok(t.includes('Pengajuan terkirim') && await judul(w, 'Pengajuan izin wali').count() === 1, 'P-Sukses: rincian menampilkan "Pengajuan terkirim"');
      ok(await terlihat(w.getByLabel('Tahap pengajuan: Wali selesai, Pembimbing sedang berjalan, Murobi belum sampai', { exact: true })).count() === 1, 'Rincian izin wali: garis waktu tahap Wali → Pembimbing → Murobi (berketerangan teks)');
      ok(await terlihat(tombol(w, 'Batalkan pengajuan')).count() === 1 && await terlihat(tombol(w, 'Muat ulang')).count() === 1, 'Wali pengaju: "Batalkan pengajuan" di bilah bawah (tindakan server)');
      await foto(w, 'izin-wali-rincian-wali-375');
      await tanpaLuapan(w, 'Izin wali rincian 375');
      await w.context().close();

      const pb = await masuk(PB);
      await pb.goto(app + '/izin-wali');
      await terlihat(pb.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${idWali}` })).waitFor({ timeout: 20000 });
      ok(await terlihat(tombol(pb, 'Ajukan izin baru')).count() === 0, 'Pembimbing: daftar tanpa FAB');
      ok(await terlihat(pb.getByRole('radio', { name: 'Perlu ditinjau' })).getAttribute('aria-checked') === 'true' && await terlihat(pb.getByRole('radio', { name: 'Sudah ditinjau' })).count() === 1, 'Pembimbing: pil Perlu ditinjau / Sudah ditinjau');
      await foto(pb, 'izin-wali-daftar-pembimbing-375');
      await pb.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${idWali}` }).click();
      await tunggu(pb, 'Tinjauan pembimbing');
      ok(await terlihat(tombol(pb, 'Teruskan ke murobi')).count() === 1, 'Pembimbing: tindakan tinjauan di bilah bawah');
      await pb.getByRole('radio', { name: 'Minta perbaikan' }).click();
      await tombol(pb, 'Minta perbaikan').click();
      await tunggu(pb, 'Catatan perbaikan untuk wali wajib');
      await pb.getByLabel('Catatan perbaikan untuk wali').fill('Perjelas jam penjemputan');
      await tombol(pb, 'Minta perbaikan').click();
      await tunggu(pb, 'Permintaan perbaikan terkirim');
      await foto(pb, 'izin-wali-rincian-pembimbing-375');
      await pb.context().close();

      const w2 = await masuk(WA);
      await w2.goto(app + `/izin-wali/${idWali}`);
      await tunggu(w2, 'Perjelas jam penjemputan');
      ok(await terlihat(w2.getByLabel('Tahap pengajuan: Wali sedang berjalan, Pembimbing belum sampai, Murobi belum sampai', { exact: true })).count() === 1, 'Perlu perbaikan: garis waktu kembali ke Wali');
      await tombol(w2, 'Perbaiki dan kirim ulang').click();
      await tunggu(w2, 'Permintaan pembimbing');
      ok(await judul(w2, 'Perbaiki pengajuan').count() === 1 && (await teks(w2)).includes('Perjelas jam penjemputan') && await w2.getByLabel('Alasan izin').inputValue() === 'Uji fase dua wali', 'Perbaiki: catatan pembimbing di kartu peringatan, kolom terisi isi lama');
      await foto(w2, 'izin-wali-perbaiki-375');
      await tanpaLuapan(w2, 'Izin wali perbaiki 375');
      await kembali(w2).first().click();
      await w2.waitForURL(u => u.pathname === `/izin-wali/${idWali}`, { timeout: 15000 }).catch(() => {});
      ok(jalur(w2) === `/izin-wali/${idWali}`, 'Perbaiki: ← kembali ke rincian');
      await w2.context().close();

      const ad = await masuk(AD);
      await ad.goto(app + '/izin-wali');
      await tunggu(ad, 'Perlu tindakan admin');
      ok(await terlihat(tombol(ad, 'Ajukan izin baru')).count() === 0, 'Admin: daftar izin wali tanpa FAB');
      await ad.context().close();
    }

    console.log('\n=== I-F. Notifikasi ===');
    {
      const tn = [];
      const m = await masuk(MU, { tangkap: tn });
      await terlihat(m.getByRole('button', { name: /^Notifikasi/ })).first().click();
      await terlihat(m.getByRole('radio', { name: /^Belum dibaca/ })).waitFor({ timeout: 20000 });
      await m.waitForTimeout(800);
      const daftar = (await api(MU, '/notifikasi?status=semua&page=1&per_page=20')).json.data;
      ok(await judul(m, 'Notifikasi').count() === 1 && await kembali(m).count() === 1, 'Notifikasi: kepala stack ber-←');
      ok(await terlihat(m.getByRole('radio', { name: 'Semua', exact: true })).getAttribute('aria-checked') === 'true' && await terlihat(m.getByRole('radio', { name: 'Sudah dibaca' })).count() === 1, 'Notifikasi: FilterPills Semua / Belum dibaca / Sudah dibaca');
      ok((await teks(m)).includes(`${daftar.jumlah_belum_dibaca} belum dibaca dari ${daftar.pagination.total} notifikasi.`), 'Notifikasi: hitungan = API');
      const baris = terlihat(m.getByRole('button', { name: /\. Belum dibaca$/ }));
      ok(await baris.count() >= 1 && /Belum dibaca/.test(await baris.first().innerText()) && /(Baru saja|menit lalu|jam lalu)/.test(await baris.first().innerText()), 'Notifikasi: baris berikon modul, judul, waktu relatif, penanda "Belum dibaca" (ikon + teks)');
      ok(await terlihat(tombol(m, 'Tandai semua dibaca')).count() === 0, 'Notifikasi: "Tandai semua dibaca" tidak lagi tombol di badan');
      await tombol(m, 'Tindakan lain').click();
      ok(await terlihat(tombol(m, 'Tandai semua dibaca')).count() === 1 && await terlihat(tombol(m, 'Perangkat & push')).count() === 1, 'Notifikasi: ⋯ memuat "Tandai semua dibaca" dan "Perangkat & push"');
      await terlihat(tombol(m, 'Tutup')).last().click();
      await foto(m, 'notifikasi-375-terang');
      await tanpaLuapan(m, 'Notifikasi 375');
      tn.length = 0;
      await baris.first().click();
      await tunggu(m, 'Notifikasi hanya memberi pemberitahuan');
      const nid = Number(jalur(m).split('/').pop());
      const baca = tn.filter(r => r.method !== 'GET');
      ok(baca.length === 1 && baca[0].path === `/notifikasi/${nid}/dibaca`, 'Membuka notifikasi: tepat satu permintaan tandai dibaca (tidak berubah)');
      ok(await judul(m, 'Rincian notifikasi').count() === 1 && await terlihat(tombol(m, 'Buka detail izin')).count() === 1, 'Rincian notifikasi: kartu pesan + tombol "Buka …" sesuai tujuan');
      await foto(m, 'notifikasi-rincian-375');
      await tombol(m, 'Buka detail izin').click();
      await tunggu(m, 'Riwayat perubahan');
      await kembali(m).first().click();
      await m.waitForURL(u => u.pathname === `/notifikasi/${nid}`, { timeout: 15000 }).catch(() => {});
      ok(jalur(m) === `/notifikasi/${nid}`, 'Rincian izin dari notifikasi: ← kembali ke notifikasi');
      await kembali(m).first().click();
      await m.waitForURL(u => u.pathname === '/notifikasi', { timeout: 15000 }).catch(() => {});
      ok(jalur(m) === '/notifikasi', 'Rincian notifikasi: ← kembali ke daftar');
      tn.length = 0;
      await tombol(m, 'Tindakan lain').click();
      await terlihat(tombol(m, 'Tandai semua dibaca')).click();
      await m.waitForTimeout(2000);
      const semua = tn.filter(r => r.method !== 'GET');
      ok(semua.length === 1 && semua[0].path === '/notifikasi/dibaca-semua' && (await api(MU, '/notifikasi/belum-dibaca')).json.data.jumlah === 0, '⋯ → "Tandai semua dibaca": satu permintaan, hitungan server 0');
      await tombol(m, 'Tindakan lain').click();
      await terlihat(tombol(m, 'Perangkat & push')).click();
      await tunggu(m, 'Seluruh perangkat akun ini');
      ok(await judul(m, 'Perangkat & push').count() === 1, 'Perangkat: kepala stack');
      await foto(m, 'notifikasi-perangkat-375');
      await tanpaLuapan(m, 'Perangkat 375');
      await kembali(m).first().click();
      await m.waitForURL(u => u.pathname === '/notifikasi', { timeout: 15000 }).catch(() => {});
      ok(jalur(m) === '/notifikasi', 'Perangkat: ← kembali ke Notifikasi');

      console.log('\n=== K. K-10: rute dibuka langsung (tanpa riwayat, seperti dari notifikasi) → ← membuka Beranda ===');
      for (const [rute, tanda, nama] of [
        [`/notifikasi/${nid}`, 'Notifikasi hanya memberi pemberitahuan', 'Rincian notifikasi'],
        ['/notifikasi', 'Sudah dibaca', 'Notifikasi'],
        ['/notifikasi/perangkat', 'Seluruh perangkat akun ini', 'Perangkat'],
        [`/izin/${idBaru}?mode=murobi`, 'Riwayat perubahan', 'Rincian izin'],
        ['/izin/99999999', 'Data belum dapat ditampilkan', 'Rincian izin yang ditolak server'],
      ]) {
        await m.goto(app + rute);
        await tunggu(m, tanda);
        await periksaKembali(m, `${nama} dibuka langsung`);
      }
      await m.context().close();
      const w = await masuk(WA);
      for (const [rute, tanda, nama] of [
        [`/izin-wali/${idWali}`, 'Perjelas jam penjemputan', 'Rincian izin wali'],
        ['/izin-wali', 'Izin anak melalui pembimbing', 'Daftar izin wali'],
        ['/izin-wali/buat', 'Rentang izin', 'Ajukan izin anak'],
        [`/izin-wali/perbaiki?id=${idWali}`, 'Permintaan pembimbing', 'Perbaiki pengajuan'],
        ['/izin-wali/99999999', 'Data belum dapat ditampilkan', 'Rincian izin wali yang ditolak server'],
      ]) {
        await w.goto(app + rute);
        await tunggu(w, tanda);
        await periksaKembali(w, `${nama} dibuka langsung`);
      }
      await w.context().close();
      const p = await masuk(PG);
      await p.goto(app + '/izin/buat?mode=pengurus');
      await p.getByRole('radio').first().waitFor({ timeout: 20000 });
      await periksaKembali(p, 'Buat izin dibuka langsung');
      await p.context().close();
    }

    console.log('\n=== L. Mode gelap dan lebar 768/1280 ===');
    for (const [lebar, tinggi, skema] of [[375, 812, 'dark'], [768, 1024, 'light'], [1280, 800, 'dark']]) {
      const nama = `${lebar}-${skema === 'dark' ? 'gelap' : 'terang'}`;
      const m = await masuk(MU, { lebar, tinggi, skema });
      for (const [rute, tanda, label] of [[`/izin/${idBaru}?mode=murobi`, 'Riwayat perubahan', 'rincian-izin'], ['/notifikasi', 'Sudah dibaca', 'notifikasi'], ['/izin/laporan?mode=murobi', 'Durasi keputusan', 'laporan-izin']]) {
        await m.goto(app + rute);
        await tunggu(m, tanda);
        await m.waitForTimeout(500);
        await tanpaLuapan(m, `${label} ${nama}`);
        await foto(m, `${label}-${nama}`);
      }
      await m.context().close();
      const w = await masuk(WA, { lebar, tinggi, skema });
      for (const [rute, tanda, label] of [['/izin-wali', 'Izin anak melalui pembimbing', 'izin-wali-daftar'], [`/izin-wali/${idWali}`, 'Perjelas jam penjemputan', 'izin-wali-rincian']]) {
        await w.goto(app + rute);
        await tunggu(w, tanda);
        await w.waitForTimeout(500);
        await tanpaLuapan(w, `${label} ${nama}`);
        await foto(w, `${label}-${nama}`);
      }
      await w.context().close();
    }
    await pg.context().close();
  }

  console.log('\n=== Z. Jaringan dan galat ===');
  ok(luar.length === 0, `Nol permintaan ke host selain 127.0.0.1 (${luar.length}${luar.length ? ': ' + [...new Set(luar)].join(', ') : ''})`);
  ok(galatHalaman.length === 0, `Tanpa pageerror (${galatHalaman.length}${galatHalaman.length ? ': ' + galatHalaman[0].slice(0, 120) : ''})`);
} catch (e) {
  console.log(`[gagal] Suite berhenti: ${String(e?.message ?? e).split('\n')[0]}`);
  gagal++;
} finally {
  await browser.close();
}
console.log(`\nHasil (${bagian}): ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
