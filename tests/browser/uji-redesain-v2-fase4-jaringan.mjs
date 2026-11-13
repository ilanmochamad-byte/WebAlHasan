import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 4 (Kesehatan, Pembinaan, Rapor,
// Keamanan) — rekam permintaan API untuk alur yang sama pada baseline dan
// kode fase, lalu bandingkan. Pemilih yang dipakai ada di kedua versi (label
// tombol/kolom dipertahankan); rute dibuka dengan alamat yang sama pada kedua
// versi bila tombolnya berpindah.
//
// Per langkah: metode, path lengkap termasuk ID (DB dipulihkan dari dump yang
// sama, jadi ID sama), query, dan badan JSON kanonik. Password dan kunci
// idempotensi disamarkan; jumlah kunci berbeda per langkah dicatat agar
// ketukan ganda yang menghasilkan dua kunci terlihat. Token tidak ditulis.
//
//   DB_NAME=test_*_test RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8987 \
//     node uji-redesain-v2-fase4-jaringan.mjs rekam <pembinaan-rapor|kesehatan|keamanan> <keluaran.json>
//   node uji-redesain-v2-fase4-jaringan.mjs banding <baseline.json> <sesudah.json>
const [mode, a, b] = process.argv.slice(2);

if (mode === 'banding') {
  const x = JSON.parse(fs.readFileSync(a, 'utf8')), y = JSON.parse(fs.readFileSync(b, 'utf8'));
  let beda = 0, langkah = 0, permintaan = 0;
  for (const kunci of new Set([...Object.keys(x), ...Object.keys(y)])) {
    langkah++;
    const kiri = (x[kunci] ?? []).slice().sort(), kanan = (y[kunci] ?? []).slice().sort();
    permintaan += kanan.length;
    if (JSON.stringify(kiri) === JSON.stringify(kanan)) { console.log(`[sama] ${kunci} (${kiri.length} permintaan)`); continue; }
    beda++;
    console.log(`[BEDA] ${kunci}`);
    for (const p of kiri.filter(p => !kanan.includes(p))) console.log(`   hanya baseline: ${p}`);
    for (const p of kanan.filter(p => !kiri.includes(p))) console.log(`   hanya sesudah : ${p}`);
    if (kiri.length !== kanan.length) console.log(`   jumlah ${kiri.length} → ${kanan.length}`);
  }
  console.log(`\nHasil: ${langkah - beda} langkah sama / ${beda} berbeda; ${permintaan} permintaan dibandingkan`);
  process.exit(beda === 0 ? 0 : 1);
}

const bagian = a, keluaran = b;
const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
if (mode !== 'rekam' || !['pembinaan-rapor', 'kesehatan', 'keamanan'].includes(bagian) || !keluaran
  || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) {
  throw Error('Pemakaian: rekam <pembinaan-rapor|kesehatan|keamanan> <keluaran.json>, hanya 127.0.0.1 dan DB test_*_test');
}
const PASS = 'Sandbox#123';
const hasil = {};
const kunciLangkah = {};
const luar = [];
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const browser = await chromium.launch({ headless: true });
// Hari ini pukul 00.30 WIB (tidak di masa depan bagi server, sama untuk baseline dan fase pada hari yang sama).
const JAM_TETAP = new Date(`${new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10)}T00:30:00+07:00`);

function kanonik(nilai, kunci = '') {
  if (/idempotency/i.test(kunci)) return '<kunci>';
  if (/^(password|password_.*|.*_password)$/i.test(kunci)) return '<sandi>';
  // Hash sumber/pratinjau dihitung server dari data dan stempel waktu run itu
  // (mis. waktu sikap diketahui); klien hanya meneruskan nilai dari respons GET.
  if (/_hash$/i.test(kunci)) return '<hash-server>';
  if (Array.isArray(nilai)) return nilai.map(v => kanonik(v));
  if (nilai && typeof nilai === 'object') return Object.fromEntries(Object.keys(nilai).sort().map(k => [k, kanonik(nilai[k], k)]));
  return nilai;
}

async function sesi(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta', serviceWorkers: 'block' });
  // Jam klien dikunci sama pada baseline dan fase: nilai bawaan "sekarang"
  // (waktu kunjungan, periode laporan) tidak bergeser antar-perekaman.
  await ctx.clock.setFixedTime(JAM_TETAP);
  const page = await ctx.newPage();
  const langkah = { nama: `${user} 00-masuk` };
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type,idempotency-key', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    let badan = '';
    const mentah = req.postData();
    if (mentah) {
      try {
        const j = JSON.parse(mentah);
        if (j && typeof j === 'object' && j.idempotency_key) (kunciLangkah[langkah.nama] ??= new Set()).add(j.idempotency_key);
        badan = ' badan=' + JSON.stringify(kanonik(j));
      } catch { badan = ' badan(bukan JSON)'; }
    }
    const kunciHeader = req.headers()['idempotency-key'];
    if (kunciHeader) (kunciLangkah[langkah.nama] ??= new Set()).add(kunciHeader);
    const query = [...u.searchParams].sort().map(([k, v]) => `${k}=${v}`).join('&');
    (hasil[langkah.nama] ??= []).push(`${req.method()} ${u.pathname}${query ? ' ?' + query : ''}${badan}`);
    try {
      const response = await route.fetch({ maxRedirects: 0 });
      await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { /* konteks ditutup */ }
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login');
  const tenang = () => page.waitForTimeout(2500);
  await tenang();
  const mulai = async nama => { await tenang(); langkah.nama = `${user} ${nama}`; aktif.page = page; aktif.langkah = langkah.nama; };
  return { page, mulai, tutup: async () => { await tenang(); await ctx.close(); } };
}
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const ke = async (p, jalur) => { await p.goto(app + jalur); };
const isi = (p, nama, v) => p.getByRole('textbox', { name: nama, exact: true }).fill(v);
/** Pilih opsi dropdown (label kolom → opsi berawalan `awal`). */
async function pilih(p, label, awal) {
  await tombol(p, label).click();
  const cari = p.getByLabel(`Cari ${label.toLowerCase()}`, { exact: true });
  if (await cari.count()) await cari.fill(awal.slice(0, 40));
  await p.getByRole('button', { name: new RegExp('^' + awal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).last().click();
}

let gagal = 0;
const langkahGagal = [];
const aktif = { page: null, langkah: '' };
async function coba(nama, fn) {
  try { await fn(); } catch (e) {
    gagal++; langkahGagal.push(`${nama} (${aktif.langkah})`);
    console.log(`[gagal] ${nama} pada "${aktif.langkah}": ${String(e.message).split('\n')[0]}`);
    if (aktif.page) await aktif.page.screenshot({ path: keluaran.replace(/\.json$/, `-gagal-${nama}.png`), fullPage: true }).catch(() => {});
  }
}

try {
  if (bagian === 'pembinaan-rapor') {
    const santri1 = sql('SELECT nama_santri FROM santri WHERE id=1');
    const katalog = sql('SELECT CONCAT(nama, " · ", tingkat, " · ", poin_default, " poin") FROM v3_katalog WHERE is_active=1 AND archived_at IS NULL ORDER BY id LIMIT 1');
    const pelanggaran = sql('SELECT id FROM v3_pelanggaran ORDER BY id LIMIT 1');

    await coba('pembimbing', async () => {
      const g = await sesi('sbx_pengurus_a'); const p = g.page;
      await g.mulai('01-menu-pembinaan'); await ke(p, '/pembinaan'); await tombol(p, 'Pelanggaran & poin').waitFor({ timeout: 30000 });
      await g.mulai('02-daftar-pelanggaran'); await tombol(p, 'Pelanggaran & poin').click(); await p.getByPlaceholder('Nama atau NIS santri').waitFor();
      await g.mulai('03-cari-nis'); await p.getByPlaceholder('Nama atau NIS santri').fill('SBX'); await tombol(p, 'Cari').click(); await tunggu(p, 'hasil untuk pencarian');
      await g.mulai('04-rincian-pelanggaran'); await ke(p, `/pembinaan/detail?jenis=pelanggaran&id=${pelanggaran}`); await tunggu(p, 'Total poin');
      await g.mulai('05-catat-pelanggaran-ketukan-ganda'); await ke(p, '/pembinaan/buat?jenis=pelanggaran');
      await tombol(p, 'Santri dalam cakupan aktif').waitFor({ timeout: 30000 });
      await pilih(p, 'Santri dalam cakupan aktif', santri1);
      await pilih(p, 'Jenis pelanggaran', katalog);
      await p.getByLabel('Waktu kejadian', { exact: true }).fill('2026-10-04T09:00');
      await p.getByLabel('Uraian kejadian', { exact: true }).fill('Jaringan F4 uraian pelanggaran');
      await tombol(p, 'Simpan').dblclick(); await p.waitForURL(u => u.pathname === '/pembinaan/detail', { timeout: 30000 }); await tunggu(p, 'Total poin');
      await g.mulai('06-buka-kasus'); await ke(p, '/pembinaan/buat?jenis=kasus');
      await tombol(p, 'Santri dalam cakupan aktif').waitFor({ timeout: 30000 });
      await pilih(p, 'Santri dalam cakupan aktif', santri1);
      await p.getByLabel('Tujuan pendampingan', { exact: true }).fill('Jaringan F4 tujuan kasus');
      await pilih(p, 'Kerahasiaan (wajib dipilih)', 'Internal');
      await tombol(p, 'Simpan').click(); await p.waitForURL(u => u.pathname === '/pembinaan/detail', { timeout: 30000 }); await tombol(p, 'Mulai pendampingan').waitFor({ timeout: 20000 });
      await g.mulai('07-tambah-sesi'); await p.getByLabel('Jadwal sesi baru / jadwal ulang', { exact: true }).fill('2026-10-05T10:00');
      await tombol(p, 'Tambahkan sesi baru').click(); await tunggu(p, 'Dijadwalkan');
      // Server memindahkan kasus ke Dalam Pendampingan saat sesi pertama ditambahkan.
      await g.mulai('08-selesaikan-sesi'); await tombol(p, 'Selesaikan kasus').waitFor({ timeout: 20000 });
      await p.getByLabel('Ringkasan internal sesi / ringkasan penutupan', { exact: true }).fill('Jaringan F4 ringkasan sesi');
      await p.getByLabel('Hasil sesi', { exact: true }).fill('Jaringan F4 hasil sesi');
      await p.getByRole('button', { name: /^Selesaikan sesi #\d+ dengan isian di bawah$/ }).click(); await tunggu(p, 'Jaringan F4 hasil sesi');
      await g.mulai('09-sikap-pembimbing-draf'); await ke(p, '/rapor/sikap');
      await tombol(p, 'Santri, kelas, dan semester').waitFor({ timeout: 30000 });
      await pilih(p, 'Santri, kelas, dan semester', 'SBX T7 Santri 13 · ');
      await p.getByRole('textbox', { name: 'Catatan pembimbing', exact: true }).fill('Jaringan F4 catatan sikap');
      await pilih(p, 'Adab/akhlak', 'Baik'); await pilih(p, 'Kedisiplinan', 'Baik'); await pilih(p, 'Tanggung jawab', 'Baik');
      await tombol(p, 'Simpan draf sikap').click(); await tunggu(p, 'Perubahan tersimpan');
      await g.tutup();
    });
    const kasusBaru = sql("SELECT id FROM v3_konseling_kasus WHERE tujuan='Jaringan F4 tujuan kasus' ORDER BY id DESC LIMIT 1");
    await coba('murobi', async () => {
      const g = await sesi('sbx_murobi_c'); const p = g.page;
      await g.mulai('10-menu-pembinaan'); await ke(p, '/pembinaan'); await p.waitForTimeout(3000);
      await g.mulai('11-sikap-murobi-diketahui'); await ke(p, '/rapor/sikap?mode=murobi');
      await tombol(p, 'Santri, kelas, dan semester').waitFor({ timeout: 30000 });
      await pilih(p, 'Santri, kelas, dan semester', 'SBX T7 Santri 11 · ');
      await tombol(p, 'Ketahui sikap tanpa mengubah isi').click(); await tunggu(p, 'Perubahan tersimpan');
      await g.tutup();
    });
    await coba('murobi-kelas-1', async () => {
      const g = await sesi('sbx_murobi_a'); const p = g.page;
      await g.mulai('12-kasus-tandai-mengetahui'); await ke(p, `/pembinaan/detail?jenis=kasus&id=${kasusBaru}`);
      await tombol(p, 'Tandai mengetahui').waitFor({ timeout: 30000 });
      await p.getByLabel('Catatan murobi / alasan tindakan', { exact: true }).fill('Jaringan F4 catatan murobi');
      await tombol(p, 'Tandai mengetahui').click(); await tunggu(p, 'Catatan murobi: Jaringan F4 catatan murobi');
      await g.tutup();
    });
    await coba('guru', async () => {
      const g = await sesi('sbx_guru_mapel'); const p = g.page;
      await g.mulai('13-menu-rapor'); await ke(p, '/rapor'); await tombol(p, 'Nilai mata pelajaran').waitFor({ timeout: 30000 });
      await g.mulai('14-nilai-pilih'); await tombol(p, 'Nilai mata pelajaran').click();
      await tombol(p, 'Kelas, semester, dan mapel').waitFor({ timeout: 30000 });
      await pilih(p, 'Kelas, semester, dan mapel', 'SBX T7 Kelas 30 · 2026/2027 Ganjil · Fiqih');
      await tombol(p, 'Santri').waitFor({ timeout: 20000 });
      await pilih(p, 'Santri', 'SBX T7 Santri 25');
      await p.getByRole('textbox', { name: 'Tengah', exact: true }).waitFor({ timeout: 20000 });
      await g.mulai('15-simpan-draf-nilai');
      await isi(p, 'Harian 1', '81'); await isi(p, 'Tengah', '82'); await isi(p, 'Akhir', '83');
      await tombol(p, 'Simpan draf nilai').click(); await tunggu(p, 'Perubahan tersimpan');
      await g.mulai('16-serahkan-nilai-ketukan-ganda'); await p.getByRole('textbox', { name: 'Tengah', exact: true }).waitFor({ timeout: 20000 });
      await tombol(p, 'Serahkan nilai').dblclick(); await tunggu(p, 'Perubahan tersimpan');
      await g.tutup();
    });
    await coba('pendidikan', async () => {
      const g = await sesi('sbx_pendidikan'); const p = g.page;
      await g.mulai('17-verifikasi-pilih'); await ke(p, '/rapor/verifikasi');
      await tombol(p, 'Kelas dan semester').waitFor({ timeout: 30000 });
      await pilih(p, 'Kelas dan semester', 'SBX T7 Kelas 30 · 2026/2027 Ganjil');
      await tombol(p, 'Santri').waitFor({ timeout: 20000 });
      await pilih(p, 'Santri', 'SBX T7 Santri 11');
      await tombol(p, 'Verifikasi Sikap').waitFor({ timeout: 20000 });
      await g.mulai('18-verifikasi-sikap'); await tombol(p, 'Verifikasi Sikap').click(); await tunggu(p, 'Perubahan tersimpan');
      await g.mulai('19-pratinjau-rapor'); await tombol(p, 'Pratinjau rapor dari server').click(); await tunggu(p, 'Versi saat ini');
      await g.tutup();
    });
    await coba('wali-rapor', async () => {
      const g = await sesi('sbx_t7_ortu'); const p = g.page;
      await g.mulai('20-menu-rapor-wali'); await ke(p, '/rapor'); await p.getByRole('button', { name: /^SBX T7 Santri 01 · / }).waitFor({ timeout: 30000 });
      await g.mulai('21-lihat-rapor'); await p.getByRole('button', { name: /^SBX T7 Santri 01 · / }).click(); await tunggu(p, 'Status rapor');
      await g.tutup();
    });
    await coba('wali-publikasi', async () => {
      const g = await sesi('sbx_ortu_a'); const p = g.page;
      await g.mulai('22-informasi-keluarga'); await ke(p, '/pembinaan'); await tombol(p, 'Informasi untuk keluarga').click();
      await p.getByRole('button', { name: /^(Terbit|Ditarik) ·/ }).first().waitFor({ timeout: 30000 });
      await g.mulai('23-buka-publikasi'); await p.getByRole('button', { name: /^(Terbit|Ditarik) ·/ }).first().click(); await tunggu(p, 'Ringkasan untuk orang tua');
      await g.mulai('24-tandai-dibaca'); await tombol(p, 'Tandai dibaca').click(); await tunggu(p, 'Dibaca ');
      await g.tutup();
    });
    await coba('admin', async () => {
      const g = await sesi('sbx_admin'); const p = g.page;
      await g.mulai('25-pembinaan-admin'); await ke(p, '/pembinaan'); await tombol(p, 'Pelanggaran & poin').waitFor({ timeout: 30000 });
      await g.mulai('26-rapor-admin'); await ke(p, '/rapor'); await p.waitForTimeout(3000);
      await g.tutup();
    });
  }

  if (bagian === 'kesehatan') {
    const nama = sql("SELECT nama_santri FROM santri WHERE nis='V5S0201'");
    await coba('petugas', async () => {
      const g = await sesi('sbx_v5_petugas_f'); const p = g.page;
      await g.mulai('01-menu'); await ke(p, '/kesehatan'); await tombol(p, 'Catat kunjungan').waitFor({ timeout: 30000 });
      await g.mulai('02-daftar'); await ke(p, '/kesehatan/daftar'); await tunggu(p, 'kunjungan ·');
      await g.mulai('03-cari'); // Kolom cari baseline tidak bernama aksesibel; pada kedua versi kolom pertama layar adalah kolom cari.
      const kolomCari = p.locator('input').first();
      await kolomCari.fill('V5S0201'); await kolomCari.press('Enter'); await p.waitForTimeout(2500);
      await g.mulai('04-catat-ketukan-ganda'); await ke(p, '/kesehatan/catat');
      await isi(p, 'Cari nama atau NIS santri aktif', 'V5S0201'); await tombol(p, 'Cari santri').click();
      await tombol(p, `${nama} · V5S0201`).click();
      await isi(p, 'Keluhan (wajib)', 'Jaringan F4 keluhan demam'); await isi(p, 'Catatan klinis', 'Jaringan F4 catatan klinis'); await isi(p, 'Tindakan', 'Jaringan F4 tindakan');
      await tombol(p, 'Tambah obat').click(); await isi(p, 'Obat 1: nama', 'Jaringan F4 obat'); await isi(p, 'Jumlah', '2');
      await tombol(p, 'Tambah rujukan').click(); await isi(p, 'Rujukan 1: tujuan', 'Jaringan F4 Puskesmas');
      await tombol(p, 'Tambah tindak lanjut').click(); await isi(p, 'Tindak lanjut 1: uraian', 'Jaringan F4 kontrol');
      await tombol(p, 'Simpan sebagai tercatat').dblclick();
      await p.waitForURL(u => /\/kesehatan\/kunjungan\/\d+$/.test(u.pathname), { timeout: 30000 }); await tunggu(p, 'Jaringan F4 obat');
      const jalur = new URL(p.url()).pathname;
      await g.mulai('05-koreksi'); await tombol(p, 'Koreksi catatan').click(); await isi(p, 'Tindakan', 'Jaringan F4 tindakan dikoreksi');
      await isi(p, 'Alasan koreksi (wajib)', 'Jaringan F4 alasan koreksi'); await tombol(p, 'Simpan koreksi').click();
      await p.waitForURL(u => u.pathname === jalur, { timeout: 30000 }); await tunggu(p, 'Jaringan F4 tindakan dikoreksi');
      await g.mulai('06-hasil-rujukan'); await tombol(p, 'Catat hasil rujukan').click(); await isi(p, 'Hasil rujukan (wajib)', 'Jaringan F4 hasil');
      await isi(p, 'Alasan (wajib)', 'Jaringan F4 hasil diterima'); await tombol(p, 'Simpan hasil rujukan').click();
      await p.waitForURL(u => u.pathname === jalur, { timeout: 30000 }); await tunggu(p, 'Jaringan F4 hasil');
      await g.mulai('07-ringkasan-pratinjau'); await ke(p, `/kesehatan/ringkasan?id=${jalur.split('/').pop()}`);
      await isi(p, 'Ringkasan (wajib, minimal 10 karakter)', 'Jaringan F4 ringkasan untuk wali.'); await isi(p, 'Penanganan di klinik', 'Jaringan F4 penanganan');
      await isi(p, 'Yang perlu diperhatikan orang tua', 'Jaringan F4 perhatian'); await tombol(p, 'Lihat pratinjau').click(); await tunggu(p, 'Pratinjau — persis seperti dilihat wali');
      await g.mulai('08-terbitkan-ketukan-ganda'); await p.getByRole('radio', { name: 'Saya sudah memeriksa pratinjau dan ingin menerbitkannya' }).click();
      await tombol(p, 'Terbitkan').dblclick(); await tunggu(p, 'Ringkasan versi 1 terbit');
      await g.mulai('09-tarik'); await isi(p, 'Alasan penarikan (wajib)', 'Jaringan F4 alasan tarik'); await tombol(p, 'Tarik ringkasan').click(); await tunggu(p, 'Ringkasan ditarik.');
      await g.mulai('10-riwayat-versi'); await ke(p, `/kesehatan/versi?id=${jalur.split('/').pop()}`); await tunggu(p, 'Versi 3');
      await g.mulai('11-laporan'); await ke(p, '/kesehatan/laporan'); await p.getByRole('radio', { name: 'Bulan lalu' }).click(); await p.waitForTimeout(2500);
      await g.tutup();
    });
    await coba('admin', async () => {
      const g = await sesi('sbx_admin'); const p = g.page;
      await g.mulai('12-penugasan'); await ke(p, '/kesehatan/penugasan'); await tombol(p, 'Tambah petugas kesehatan').waitFor({ timeout: 30000 });
      const kid = sql("SELECT id FROM kesehatan_kunjungan WHERE keluhan='Jaringan F4 keluhan demam' LIMIT 1");
      await g.mulai('13-riwayat-akses'); await ke(p, `/kesehatan/akses?id=${kid}`); await tunggu(p, 'pembukaan');
      await g.tutup();
    });
    await coba('pembimbing', async () => {
      const g = await sesi('sbx_v5_pembimbing'); const p = g.page;
      await g.mulai('14-binaan'); await ke(p, '/kesehatan'); await tombol(p, 'Riwayat kesehatan santri binaan').click(); await p.waitForTimeout(3000);
      await g.tutup();
    });
    await coba('wali', async () => {
      const g = await sesi('sbx_v5_wali_1'); const p = g.page;
      await g.mulai('15-wali-daftar'); await ke(p, '/kesehatan/wali'); await p.waitForTimeout(3000);
      const rid = sql("SELECT r.id FROM kesehatan_ringkasan r JOIN kesehatan_kunjungan k ON k.id=r.kunjungan_id WHERE k.keluhan='Jaringan F4 keluhan demam' LIMIT 1");
      await g.mulai('16-wali-rincian'); await ke(p, `/kesehatan/wali/${rid}`); await tunggu(p, 'Informasi ini telah ditarik oleh pesantren.');
      await g.tutup();
    });
  }

  if (bagian === 'keamanan') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    await coba('keamanan', async () => {
      const g = await sesi('sbx_pengurus_biasa'); const p = g.page;
      await g.mulai('01-menu'); await ke(p, '/keamanan'); await p.getByRole('button', { name: /^Perizinan santri/ }).waitFor({ timeout: 30000 });
      await g.mulai('02-izin'); await p.getByRole('button', { name: /^Perizinan santri/ }).click(); await tunggu(p, 'pengajuan ·');
      await g.mulai('03-izin-asal-wali'); await p.getByRole('radio', { name: 'Wali', exact: true }).click(); await p.waitForTimeout(2000);
      await g.mulai('04-izin-cari'); await p.getByLabel('Cari santri').fill('SBX'); await tombol(p, 'Cari').click(); await p.waitForTimeout(2000);
      await g.mulai('05-izin-rincian'); await ke(p, `/keamanan/izin/${ids.izin_wali}`); await tunggu(p, 'Keputusan');
      await g.mulai('06-pelanggaran'); await ke(p, '/keamanan/pelanggaran'); await tunggu(p, ' dari ');
      await g.mulai('07-pelanggaran-rincian'); await ke(p, `/keamanan/pelanggaran/${ids.pelanggaran_internal}`); await tunggu(p, 'Riwayat catatan');
      await g.mulai('08-konseling'); await ke(p, '/keamanan/konseling'); await tunggu(p, ' dari ');
      await g.mulai('09-konseling-rincian'); await ke(p, `/keamanan/konseling/${ids.kasus_internal}`); await tunggu(p, 'Pelanggaran tertaut');
      await g.mulai('10-kehadiran'); await ke(p, '/keamanan/kehadiran'); await tunggu(p, 'pertemuan ·');
      await g.tutup();
    });
  }
} finally {
  await browser.close();
}
const kunci = Object.fromEntries(Object.entries(kunciLangkah).map(([k, v]) => [k, v.size]));
fs.writeFileSync(keluaran, JSON.stringify(hasil, null, 1));
fs.writeFileSync(keluaran.replace(/\.json$/, '-kunci.json'), JSON.stringify(kunci, null, 1));
console.log(`Direkam: ${Object.keys(hasil).length} langkah, ${Object.values(hasil).reduce((n, l) => n + l.length, 0)} permintaan; kunci per langkah mutasi: ${JSON.stringify(kunci)}`);
console.log(`Permintaan keluar 127.0.0.1: ${luar.length}`);
if (gagal) { console.log(`Langkah gagal: ${langkahGagal.join(', ')}`); process.exit(1); }
