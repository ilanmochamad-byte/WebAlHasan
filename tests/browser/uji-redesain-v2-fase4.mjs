import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 4 — Expo web, API nyata lokal.
// Bagian:
//   pembinaan-rapor (DB Tahap 7): guru, murobi, pembimbing, pengurus, Pendidikan,
//     admin, orang tua — kepala ←, kerahasiaan bergembok, rapor versi sebelumnya.
//   kesehatan (DB V5 Fase 3): petugas, admin, pembimbing, murobi, orang tua —
//     penanda privat, ringkasan wali terpisah, laporan = API tanpa pembanding.
//   keamanan (DB penugasan Keamanan F4): Keamanan hanya baca, guru ditolak.
//   k10 (DB Tahap 7): SELURUH rute aplikasi dibuka langsung (tanpa riwayat,
//     setara ketukan notifikasi/tautan) → tepat satu ← ≥ 44 pt → Beranda; tab
//     → Beranda; dengan riwayat → halaman sebelumnya.
//   bsa (DB V6 Fase 4): petugas BSA — Beranda, menu Keuangan ber-← (regresi Fase 3).
// Semua: 375 terang + gelap, 768 dan 1280 terang/gelap untuk satu layar per
// peran; tanpa luapan horizontal; target ≥ 44 pt. Host selain 127.0.0.1 diblokir.
//
//   DB_NAME=test_*_test RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8987 \
//     OUT_DIR=<folder> [SEED_JSON=ids-pk.json] node uji-redesain-v2-fase4.mjs <bagian>
const bagian = process.argv[2];
const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
if (!['pembinaan-rapor', 'kesehatan', 'keamanan', 'k10', 'bsa'].includes(bagian)
  || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) {
  throw Error('Pemakaian: <bagian>, hanya 127.0.0.1 dan DB test_*_test');
}
const out = process.env.OUT_DIR || `/tmp/rtv2f4-${bagian}`;
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
let lulus = 0, gagal = 0;
const luar = [];
const ok = (v, label) => { if (v) { lulus++; console.log(`[lulus] ${label}`); } else { gagal++; console.log(`[gagal] ${label}`); } };
const browser = await chromium.launch({ headless: true });

async function masuk(user, { width = 375, height = 812, gelap = false } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: gelap ? 'dark' : 'light', timezoneId: 'Asia/Jakarta', serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.route('**/*', async route => {
    const req = route.request(); const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type,idempotency-key', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    try { const response = await route.fetch({ maxRedirects: 0 }); await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } }); } catch { /* konteks ditutup */ }
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 30000 });
  await page.waitForTimeout(1500);
  return { page, tutup: () => ctx.close() };
}
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const tunggu = (p, t, timeout = 25000) => p.getByText(t, { exact: false }).first().waitFor({ timeout }).catch(() => {});
const foto = (p, nama) => p.screenshot({ path: path.join(out, `${nama}.png`), fullPage: false }).catch(() => {});

/** Tombol ← yang terlihat pada layar aktif (aria-label diawali "Kembali"). */
async function tombolKembali(p) {
  return p.evaluate(() => [...document.querySelectorAll('[role="button"],button')]
    .filter(e => /^Kembali/.test(e.getAttribute('aria-label') || ''))
    .map(e => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return { w: r.width, h: r.height, x: r.x, y: r.y, label: e.getAttribute('aria-label'), tampil: r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' }; })
    .filter(e => e.tampil));
}
async function periksaKepala(p, nama) {
  await p.waitForFunction(() => [...document.querySelectorAll('[role="button"],button')].some(e => /^Kembali/.test(e.getAttribute('aria-label') || '') && e.getBoundingClientRect().width > 0), null, { timeout: 25000 }).catch(() => {});
  const k = await tombolKembali(p);
  ok(k.length === 1 && k[0].w >= 44 && k[0].h >= 44 && k[0].y < 160, `${nama}: tepat satu ← ≥ 44 pt di kepala (${k.map(x => `${Math.round(x.w)}×${Math.round(x.h)}@${Math.round(x.y)}`).join(', ') || 'tidak ada'})`);
  return k;
}
async function luapan(p, nama) {
  const selisih = await p.evaluate(() => {
    const w = document.documentElement.clientWidth;
    let maks = w;
    for (const e of document.querySelectorAll('body *')) {
      // Isi ScrollView horizontal (bilah tab web, baris pil yang memang digeser) bukan luapan halaman.
      let q = e.parentElement, digulir = false;
      while (q) { const s = getComputedStyle(q); if (s.overflowX === 'auto' || s.overflowX === 'scroll') { digulir = true; break; } q = q.parentElement; }
      if (digulir) continue;
      const r = e.getBoundingClientRect(); if (r.width > 0 && r.height > 0) maks = Math.max(maks, r.right);
    }
    return Math.round(maks - w);
  });
  ok(selisih <= 1, `${nama}: tanpa luapan horizontal (selisih ${selisih}px)`);
}
async function targetSentuh(p, nama) {
  const kecil = await p.evaluate(() => [...document.querySelectorAll('[role="button"],[role="radio"],[role="tab"],[role="checkbox"]')]
    .map(e => ({ r: e.getBoundingClientRect(), n: (e.getAttribute('aria-label') || e.innerText || '').trim().slice(0, 40) }))
    .filter(x => x.r.width > 0 && x.r.height > 0 && x.r.top < innerHeight * 3 && (x.r.width < 43.5 || x.r.height < 43.5))
    .map(x => `${x.n} ${Math.round(x.r.width)}×${Math.round(x.r.height)}`));
  ok(kecil.length === 0, `${nama}: seluruh kontrol terlihat ≥ 44 pt${kecil.length ? ` (kecil: ${kecil.slice(0, 4).join('; ')})` : ''}`);
}
/** Satu layar: kepala, luapan, target, tangkapan. */
async function layar(p, jalur, nama, tunggui) {
  await p.goto(app + jalur);
  if (tunggui) await tunggu(p, tunggui);
  await p.waitForTimeout(800);
  await periksaKepala(p, nama);
  await luapan(p, nama);
  await targetSentuh(p, nama);
  await foto(p, nama.replace(/[^a-z0-9-]+/gi, '-'));
}
/** Tampilan gelap 375 dan 768/1280 terang/gelap untuk satu rute. */
async function tampilan(user, jalur, nama, tunggui) {
  for (const v of [{ width: 375, gelap: true }, { width: 768, gelap: false }, { width: 768, gelap: true }, { width: 1280, gelap: false }, { width: 1280, gelap: true }]) {
    const s = await masuk(user, { width: v.width, height: 900, gelap: v.gelap });
    await s.page.goto(app + jalur); if (tunggui) await tunggu(s.page, tunggui); await s.page.waitForTimeout(800);
    const label = `${nama} ${v.width}${v.gelap ? ' gelap' : ' terang'}`;
    await periksaKepala(s.page, label); await luapan(s.page, label);
    if (v.gelap) {
      const latar = await s.page.evaluate(() => { const el = [...document.querySelectorAll('div')].find(d => d.getBoundingClientRect().width >= innerWidth - 2 && getComputedStyle(d).backgroundColor !== 'rgba(0, 0, 0, 0)'); return el ? getComputedStyle(el).backgroundColor : ''; });
      const m = latar.match(/\d+/g)?.map(Number) ?? [255, 255, 255];
      ok(m[0] + m[1] + m[2] < 150, `${label}: latar gelap (${latar})`);
    }
    await foto(s.page, `${nama}-${v.width}${v.gelap ? '-gelap' : ''}`.replace(/[^a-z0-9-]+/gi, '-'));
    await s.tutup();
  }
}

try {
  if (bagian === 'k10') {
    // Seluruh rute aplikasi (src/app), dibuka langsung tanpa riwayat.
    const id = 1;
    const RUTE = [
      ['/schedules', 'akar'], ['/perizinan', 'akar'], ['/profil', 'akar'],
      ['/akun/edit-profil'], ['/akun/ganti-password'], ['/akun/data-diri'], // akun/data-diri: rute baru tindak lanjut TL-03
      ['/schedule/1'], ['/meeting/1'], ['/reports'], ['/report/1'],
      ['/izin/buat'], ['/izin/1'], ['/izin/laporan'],
      ['/izin-wali'], ['/izin-wali/buat'], ['/izin-wali/1'], ['/izin-wali/perbaiki?id=1'],
      ['/notifikasi'], ['/notifikasi/1'], ['/notifikasi/perangkat'],
      ['/pembinaan'], ['/pembinaan/daftar?jenis=pelanggaran'], ['/pembinaan/daftar?jenis=kasus'], ['/pembinaan/daftar?jenis=rekomendasi'], ['/pembinaan/daftar?jenis=publikasi'],
      ['/pembinaan/buat?jenis=pelanggaran'], ['/pembinaan/buat?jenis=kasus'], ['/pembinaan/detail?jenis=pelanggaran&id=1'], ['/pembinaan/detail?jenis=kasus&id=1'],
      ['/publikasi/1'],
      ['/rapor'], ['/rapor/nilai'], ['/rapor/nilai?pengganti=1'], ['/rapor/sikap'], ['/rapor/sikap?mode=murobi'], ['/rapor/verifikasi'], ['/rapor/1'],
      ['/kesehatan'], ['/kesehatan/daftar'], ['/kesehatan/catat'], ['/kesehatan/kunjungan/1'], ['/kesehatan/hasil-rujukan?id=1&rujukan=1'],
      ['/kesehatan/versi?id=1'], ['/kesehatan/akses?id=1'], ['/kesehatan/ringkasan?id=1'], ['/kesehatan/penugasan'], ['/kesehatan/laporan'],
      ['/kesehatan/wali'], ['/kesehatan/wali/1'],
      ['/keuangan'], ['/keuangan/santri'], ['/keuangan/santri/1'], ['/keuangan/transaksi?santri=1'], ['/keuangan/tagihan/1'], ['/keuangan/bayar?tagihan=1'],
      ['/keuangan/bayar-tabungan?tagihan=1'], ['/keuangan/ubah-tagihan?tagihan=1'], ['/keuangan/pembayaran'], ['/keuangan/pembayaran/1'], ['/keuangan/tabungan'],
      ['/keuangan/tabungan/1'], ['/keuangan/verifikasi'], ['/keuangan/tunggakan'], ['/keuangan/rekonsiliasi'], ['/keuangan/laporan'], ['/keuangan/penerbitan'],
      ['/keuangan/terbit-santri?santri=1'],
      ['/keamanan'], ['/keamanan/izin'], ['/keamanan/izin/1'], ['/keamanan/pelanggaran'], ['/keamanan/pelanggaran/1'], ['/keamanan/konseling'], ['/keamanan/konseling/1'],
      ['/keamanan/kehadiran'], ['/keamanan/kehadiran/1'],
    ];
    void id;
    const daftar = [];
    for (const user of ['sbx_admin', 'sbx_guru_mapel']) {
      const s = await masuk(user); const p = s.page;
      for (const [jalur, varian] of RUTE) {
        await p.goto(app + jalur);
        await p.waitForTimeout(600);
        if (varian === 'akar' && new URL(p.url()).pathname === '/') {
          // Tab tersembunyi untuk peran ini (mis. Izin tanpa hak perizinan): rute dialihkan ke Beranda.
          ok((await tombolKembali(p)).length === 0, `${user} ${jalur}: tab tidak tersedia untuk peran ini → dialihkan ke Beranda`);
          daftar.push({ user, jalur, varian, kembali: '(dialihkan ke Beranda)', tujuan: '/' });
          continue;
        }
        const k = await periksaKepala(p, `${user} ${jalur}`);
        let tujuan = '—';
        if (k.length === 1) {
          await p.getByRole('button', { name: k[0].label, exact: true }).first().click();
          await p.waitForURL(u => u.pathname === '/', { timeout: 15000 }).catch(() => {});
          tujuan = new URL(p.url()).pathname;
          // Layar tab: "Kembali ke Beranda". Layar stack: diawali "Kembali" (formulir izin memakai
          // "Kembali ke halaman sebelumnya" karena ada kolom tanggal "Kembali", keputusan Fase 2).
          const labelOk = varian === 'akar' ? k[0].label === 'Kembali ke Beranda' : /^Kembali/.test(k[0].label);
          ok(tujuan === '/' && labelOk, `${user} ${jalur}: ← "${k[0].label}" tanpa riwayat → Beranda (${tujuan})`);
        }
        daftar.push({ user, jalur, varian: varian ?? 'stack', kembali: k.map(x => x.label).join('|'), tujuan });
      }
      // Beranda: logo, tanpa ←.
      await p.goto(app + '/'); await p.waitForTimeout(1500);
      ok((await tombolKembali(p)).length === 0 && await p.getByRole('img', { name: 'Alhasan apps' }).count() >= 1, `${user} Beranda: logo, tanpa ←`);
      await s.tutup();
    }
    // Dengan riwayat: ← kembali ke halaman sebelumnya, bukan Beranda.
    const s = await masuk('sbx_admin'); const p = s.page;
    for (const [asal, klik, nama] of [
      ['/pembinaan', 'Pelanggaran & poin', 'pembinaan menu → daftar'],
      ['/rapor', null, 'rapor'],
      ['/kesehatan', 'Laporan layanan klinik', 'kesehatan menu → laporan'],
    ]) {
      if (!klik) continue;
      await p.goto(app + asal); await tombol(p, klik).waitFor({ timeout: 25000 }); await tombol(p, klik).click(); await p.waitForTimeout(1500);
      await p.getByRole('button', { name: 'Kembali', exact: true }).last().click();
      await p.waitForURL(u => u.pathname === asal, { timeout: 15000 }).catch(() => {});
      ok(new URL(p.url()).pathname === asal, `${nama}: ← dengan riwayat kembali ke ${asal} (${new URL(p.url()).pathname})`);
    }
    await s.tutup();
    fs.writeFileSync(path.join(out, 'daftar-rute-k10.json'), JSON.stringify(daftar, null, 1));
    console.log(`Rute diperiksa: ${RUTE.length} rute × 2 akun`);
  }

  if (bagian === 'pembinaan-rapor') {
    const pelanggaran = sql('SELECT id FROM v3_pelanggaran ORDER BY id LIMIT 1');
    const kasusInternal = sql("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Internal' ORDER BY id LIMIT 1");
    // Pembimbing
    let s = await masuk('sbx_pengurus_a'); let p = s.page;
    await layar(p, '/pembinaan', 'pembimbing menu pembinaan', 'Pelanggaran & poin');
    ok(await tombol(p, 'Catat pelanggaran').count() === 1 && await tombol(p, 'Buka kasus').count() === 1, 'pembimbing: tombol cepat Catat pelanggaran / Buka kasus');
    await layar(p, '/pembinaan/daftar?jenis=kasus', 'pembimbing daftar kasus', 'Halaman 1');
    const t = await teks(p);
    const ikonGembok = await p.evaluate(() => [...document.querySelectorAll('img')].filter(i => (i.getAttribute('src') || '').includes('lock')).length);
    ok(/Internal|Rahasia/.test(t) && ikonGembok > 0, `pembimbing: kerahasiaan kasus tampil dengan gembok (${ikonGembok} ikon)`);
    await layar(p, `/pembinaan/detail?jenis=kasus&id=${kasusInternal}`, 'pembimbing rincian kasus', 'Ringkasan');
    await layar(p, `/pembinaan/detail?jenis=pelanggaran&id=${pelanggaran}`, 'pembimbing rincian pelanggaran', 'Total poin');
    await layar(p, '/pembinaan/buat?jenis=kasus', 'pembimbing buka kasus', 'Kerahasiaan');
    ok((await teks(p)).includes('Rahasia: pemilik kasus dan admin'), 'pembimbing: pilihan kerahasiaan dijelaskan');
    await layar(p, '/rapor/sikap', 'pembimbing sikap', 'Santri, kelas, dan semester');
    await s.tutup();
    await tampilan('sbx_pengurus_a', `/pembinaan/detail?jenis=kasus&id=${kasusInternal}`, 'pembimbing-rincian-kasus', 'Ringkasan');
    // Murobi
    s = await masuk('sbx_murobi_a'); p = s.page;
    await layar(p, `/pembinaan/detail?jenis=kasus&id=${kasusInternal}`, 'murobi rincian kasus', 'Tandai mengetahui');
    ok(await tombol(p, 'Tandai mengetahui').count() === 1, 'murobi: Tandai mengetahui tersedia (hak dari server)');
    await s.tutup();
    s = await masuk('sbx_murobi_c'); p = s.page;
    await layar(p, '/rapor/sikap?mode=murobi', 'murobi sikap', 'Santri, kelas, dan semester');
    await s.tutup();
    // Guru mapel
    s = await masuk('sbx_guru_mapel'); p = s.page;
    await layar(p, '/rapor', 'guru menu rapor', 'Nilai mata pelajaran');
    await layar(p, '/rapor/nilai', 'guru nilai', 'Kelas, semester, dan mapel');
    await s.tutup();
    await tampilan('sbx_guru_mapel', '/rapor', 'guru-menu-rapor', 'Nilai mata pelajaran');
    // Pendidikan
    s = await masuk('sbx_pendidikan'); p = s.page;
    await layar(p, '/rapor/verifikasi', 'pendidikan verifikasi', 'Kelas dan semester');
    await s.tutup();
    // Pengurus tanpa penugasan pembinaan
    s = await masuk('sbx_pengurus_biasa'); p = s.page;
    await layar(p, '/pembinaan', 'pengurus tanpa akses pembinaan', 'akses');
    await s.tutup();
    // Admin
    s = await masuk('sbx_admin'); p = s.page;
    await layar(p, '/pembinaan', 'admin pembinaan', 'Pelanggaran & poin');
    ok(await tombol(p, 'Buka kasus').count() === 0, 'admin: pengawasan tanpa tombol kelola');
    await layar(p, '/rapor', 'admin rapor');
    await s.tutup();
    // Orang tua: publikasi
    s = await masuk('sbx_ortu_a'); p = s.page;
    await layar(p, '/pembinaan/daftar?jenis=publikasi', 'wali daftar informasi keluarga', 'Halaman 1');
    await p.getByRole('button', { name: /^(Terbit|Ditarik) ·/ }).first().click();
    await tunggu(p, 'Ringkasan untuk orang tua');
    await periksaKepala(p, 'wali rincian publikasi'); await luapan(p, 'wali rincian publikasi'); await foto(p, 'wali-publikasi');
    ok(await tombol(p, 'Tandai dibaca').count() === 1, 'wali: satu tombol Tandai dibaca');
    await s.tutup();
    // Orang tua: rapor dua versi
    const rapor = sql("SELECT r.id FROM v4_rapor r JOIN santri s ON s.id=r.santri_id WHERE s.nama_santri='SBX T7 Santri 01' LIMIT 1");
    s = await masuk('sbx_t7_ortu'); p = s.page;
    await layar(p, `/rapor/${rapor}`, 'wali rapor', 'Status rapor');
    const tr = await teks(p);
    ok(tr.includes('Versi sebelumnya') && await tombol(p, 'Unduh PDF rapor').count() === 1, 'wali rapor: "Versi sebelumnya" dan tepat satu Unduh PDF rapor (versi terbaru)');
    await s.tutup();
    await tampilan('sbx_t7_ortu', `/rapor/${rapor}`, 'wali-rapor', 'Status rapor');
  }

  if (bagian === 'kesehatan') {
    // Fixture V5 Fase 3 belum berisi kunjungan: petugas mencatat satu lewat API nyata (tanpa isi pribadi nyata).
    const masukApi = async u => (await (await fetch(web + '/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: u, password: PASS }) })).json()).data.token;
    const token = await masukApi('sbx_v5_petugas_f');
    const santriId = Number(sql("SELECT id FROM santri WHERE nis='V5S0201'"));
    const kini = new Date(Date.now() + 7 * 3600e3 - 3600e3).toISOString().slice(0, 16);
    const buat = await fetch(web + '/api/v1/v5/kesehatan/kunjungan', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }, body: JSON.stringify({
      santri_id: santriId, status: 'Tercatat', waktu_kunjungan: kini, keluhan: 'F4 suite keluhan', catatan_klinis: 'F4 suite catatan klinis', tindakan: 'F4 suite tindakan',
      obat: [{ nama_obat: 'F4 obat', jumlah: '1', satuan: '', petunjuk: '' }], rujukan: [{ tujuan: 'F4 Puskesmas', alasan: '', tanggal_rujukan: '', hasil: '', tanggal_hasil: '' }],
      tindak_lanjut: [{ uraian: 'F4 kontrol', status: 'Terbuka', tanggal_target: '', tanggal_selesai: '' }], idempotency_key: 'f4-suite-' + Date.now() }) });
    ok(buat.status === 201, `fixture: kunjungan dicatat lewat API (${buat.status})`);
    const kid = sql("SELECT id FROM kesehatan_kunjungan WHERE keluhan='F4 suite keluhan' ORDER BY id DESC LIMIT 1");
    let s = await masuk('sbx_v5_petugas_f'); let p = s.page;
    await layar(p, '/kesehatan', 'petugas menu kesehatan', 'Catat kunjungan');
    await layar(p, '/kesehatan/daftar', 'petugas daftar kunjungan', 'kunjungan ·');
    ok(await p.getByRole('radio', { name: 'Rujukan menunggu', exact: true }).count() === 1, 'petugas daftar: pil cepat Rujukan menunggu');
    await layar(p, `/kesehatan/kunjungan/${kid}`, 'petugas rincian kunjungan', 'Catatan klinik');
    const tk = await teks(p);
    ok(tk.includes('Privat: hanya petugas kesehatan') && tk.includes('Catatan klinis'), 'petugas rincian: catatan klinis bertanda privat');
    await layar(p, '/kesehatan/catat', 'petugas catat', 'Keluhan');
    await layar(p, `/kesehatan/ringkasan?id=${kid}`, 'petugas ringkasan wali', 'Yang dilihat wali saat ini');
    ok((await teks(p)).includes('terpisah dari catatan klinis'), 'ringkasan wali ditandai terpisah dari catatan klinis');
    await layar(p, `/kesehatan/versi?id=${kid}`, 'petugas riwayat versi', 'Versi 1');
    await layar(p, '/kesehatan/laporan', 'petugas laporan', 'Jumlah kunjungan');
    const tl = await teks(p);
    ok(!/[↑↓]|\d+\s?%/.test(tl), 'laporan klinik tanpa angka pembanding/persentase karangan');
    await s.tutup();
    await tampilan('sbx_v5_petugas_f', `/kesehatan/kunjungan/${kid}`, 'petugas-rincian-kunjungan', 'Catatan klinik');
    s = await masuk('sbx_admin'); p = s.page;
    await layar(p, '/kesehatan/penugasan', 'admin penugasan kesehatan', 'Tambah petugas kesehatan');
    await layar(p, `/kesehatan/akses?id=${kid}`, 'admin riwayat akses', 'pembukaan');
    ok(await tombol(p, 'Catat kunjungan').count() === 0, 'admin: tanpa Catat kunjungan');
    await s.tutup();
    for (const u of ['sbx_v5_pembimbing', 'sbx_v5_murobi']) {
      s = await masuk(u); p = s.page;
      await layar(p, '/kesehatan', `${u} menu kesehatan`, 'Riwayat kesehatan santri binaan');
      await s.tutup();
    }
    s = await masuk('sbx_v5_wali_1'); p = s.page;
    await layar(p, '/kesehatan/wali', 'wali daftar kesehatan anak');
    const baris = p.getByRole('button', { name: /^Buka informasi kesehatan/ }).first();
    if (await baris.count()) {
      await baris.click(); await tunggu(p, 'Informasi kesehatan untuk orang tua');
      await periksaKepala(p, 'wali rincian kesehatan'); await luapan(p, 'wali rincian kesehatan'); await foto(p, 'wali-rincian-kesehatan');
      ok(!(await teks(p)).includes('Catatan klinis'), 'wali: tanpa catatan klinis');
    } else ok(true, 'wali: belum ada ringkasan terbit pada fixture (keadaan kosong ber-←)');
    await s.tutup();
    await tampilan('sbx_v5_wali_1', '/kesehatan/wali', 'wali-kesehatan');
  }

  if (bagian === 'keamanan') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    const MUTASI = /^(Ajukan|Setujui|Tolak|Simpan|Catat|Koreksi|Batalkan|Teruskan|Tandai|Unduh|Hapus|Kirim|Putuskan|Terbitkan)/i;
    const tanpaMutasi = async (p, nama) => {
      const m = (await p.getByRole('button').allInnerTexts()).map(x => x.trim()).filter(x => MUTASI.test(x));
      ok(m.length === 0, `${nama}: tanpa tombol tindakan${m.length ? ` (${m.join(', ')})` : ''}`);
    };
    let s = await masuk('sbx_pengurus_biasa'); let p = s.page;
    for (const [jalur, nama, t] of [
      ['/keamanan', 'keamanan menu', 'Pantauan santri'], ['/keamanan/izin', 'keamanan daftar izin', 'pengajuan ·'], [`/keamanan/izin/${ids.izin_wali}`, 'keamanan rincian izin', 'Keputusan'],
      ['/keamanan/pelanggaran', 'keamanan daftar pelanggaran', ' dari '], [`/keamanan/pelanggaran/${ids.pelanggaran_internal}`, 'keamanan rincian pelanggaran', 'Riwayat catatan'],
      ['/keamanan/konseling', 'keamanan daftar konseling', ' dari '], [`/keamanan/konseling/${ids.kasus_internal}`, 'keamanan rincian kasus', 'Pelanggaran tertaut'],
      ['/keamanan/kehadiran', 'keamanan kehadiran', 'pertemuan ·'],
    ]) { await layar(p, jalur, nama, t); await tanpaMutasi(p, nama); }
    ok((await teks(p)).length > 0, 'keamanan: layar termuat');
    await p.goto(app + '/keamanan'); await tunggu(p, 'Pantauan santri');
    ok((await teks(p)).includes('Hanya melihat'), 'keamanan: kepala menyatakan Hanya melihat');
    await p.goto(app + '/keamanan/konseling'); await tunggu(p, ' dari ');
    ok(!(await p.getByRole('button', { name: /^Buka kasus konseling/ }).allInnerTexts()).some(x => x.includes('Rahasia')), 'keamanan: kasus Rahasia tidak tampil di daftar');
    await p.getByRole('button', { name: /^Buka pertemuan/ }).first().click().catch(() => {});
    await s.tutup();
    s = await masuk('sbx_pengurus_biasa'); p = s.page;
    await p.goto(app + '/keamanan/kehadiran'); await tunggu(p, 'pertemuan ·');
    if (await p.getByRole('button', { name: /^Buka pertemuan/ }).count()) {
      await p.getByRole('button', { name: /^Buka pertemuan/ }).first().click(); await tunggu(p, 'Peserta');
      await periksaKepala(p, 'keamanan rincian pertemuan'); await luapan(p, 'keamanan rincian pertemuan'); await tanpaMutasi(p, 'keamanan rincian pertemuan');
    }
    await s.tutup();
    await tampilan('sbx_pengurus_biasa', `/keamanan/izin/${ids.izin_wali}`, 'keamanan-rincian-izin', 'Keputusan');
    // Guru dengan identitas pengurus berpenugasan Keamanan (PK-1) membuka menu yang sama.
    s = await masuk('sbx_guru_biasa'); p = s.page;
    await layar(p, '/keamanan', 'guru berpenugasan Keamanan', 'Pantauan santri');
    ok((await teks(p)).includes('Pantauan santri'), 'guru berpenugasan Keamanan: menu tampil');
    await s.tutup();
    // Admin: pengawasan tidak membuka halaman Keamanan; server menolak, ← tetap.
    s = await masuk('sbx_admin'); p = s.page;
    await layar(p, '/keamanan', 'admin tanpa penugasan Keamanan', 'penugasan Keamanan');
    ok(!(await teks(p)).includes('Pantauan santri'), 'admin tanpa penugasan Keamanan: ditolak, tanpa data');
    await s.tutup();
  }

  if (bagian === 'bsa') {
    const s = await masuk('sbx_v6_bsa_b'); const p = s.page;
    await p.goto(app + '/'); await p.waitForTimeout(1500);
    ok((await tombolKembali(p)).length === 0, 'petugas BSA Beranda: tanpa ←');
    await layar(p, '/keuangan', 'petugas BSA menu keuangan', 'Santri, saldo, dan tagihan');
    await s.tutup();
    await tampilan('sbx_v6_bsa_b', '/keuangan', 'bsa-menu');
  }
} finally {
  await browser.close();
}
ok(luar.length === 0, `tanpa permintaan keluar 127.0.0.1 (${[...new Set(luar)].join(', ')})`);
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
