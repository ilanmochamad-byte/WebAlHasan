import fs from 'node:fs';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 2 — rekam permintaan API untuk
// alur yang sama pada baseline dan kode fase, lalu bandingkan. Pemilih
// menyesuaikan letak kontrol yang pindah ke ⋯ atau lembar keputusan, serta
// label aksesibilitas tombol pengajuan wali.
//
// Yang direkam per langkah: metode, path lengkap termasuk ID, query (kunci =
// nilai), dan badan JSON kanonik. Password disamarkan; kunci idempotensi
// (acak per percobaan) diganti penanda. Token tidak pernah ditulis.
// Dijalankan pada DB yang dipulihkan ke keadaan sama sebelum tiap perekaman.
//
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8962 SEED_JSON=ids.json \
//     node uji-redesain-v2-fase2-jaringan.mjs rekam pengajian <keluaran.json>
//   RTV2_WEB=… RTV2_APP=… node uji-redesain-v2-fase2-jaringan.mjs rekam izin <keluaran.json>
//   node uji-redesain-v2-fase2-jaringan.mjs banding <baseline.json> <sesudah.json>
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

const web = process.env.RTV2_WEB, app = process.env.RTV2_APP;
const bagian = a, keluaran = b;
if (mode !== 'rekam' || !['pengajian', 'izin'].includes(bagian) || !keluaran || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) {
  throw Error('Pemakaian: rekam <pengajian|izin> <keluaran.json>, hanya 127.0.0.1');
}
const PASS = 'Sandbox#123';
const hasil = {};
const luar = [];
const browser = await chromium.launch({ headless: true });

function kanonik(nilai, kunci = '') {
  if (/idempotency/i.test(kunci)) return '<kunci>';
  if (/^(password|password_.*|.*_password)$/i.test(kunci)) return '<sandi>';
  if (Array.isArray(nilai)) return nilai.map(v => kanonik(v));
  if (nilai && typeof nilai === 'object') return Object.fromEntries(Object.keys(nilai).sort().map(k => [k, kanonik(nilai[k], k)]));
  return nilai;
}

/** Membuka konteks 375 px, masuk sebagai `user`, dan merekam ke `langkah.nama`. */
async function sesi(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  const langkah = { nama: `${user} 0-masuk` };
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    let badan = '';
    const mentah = req.postData();
    if (mentah) {
      try { badan = ' badan=' + JSON.stringify(kanonik(JSON.parse(mentah))); } catch { badan = ' badan(bukan JSON)'; }
    }
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
  const mulai = async nama => { await tenang(); langkah.nama = `${user} ${nama}`; };
  return { page, mulai, tenang, tutup: async () => { await tenang(); await ctx.close(); } };
}
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
/** Membuka rute langsung dengan cara yang sama pada kedua versi. */
const ke = async (p, jalur) => { await p.goto(app + jalur); };

try {
  if (bagian === 'pengajian') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    const T = ids.tanggal, JG = ids.jadwal_gabungan, sfx = ids.sfx;
    const g = await sesi('sbx_guru_biasa');
    const p = g.page;

    await g.mulai('1-jadwal');
    await ke(p, '/schedules');
    await tunggu(p, `Nahwu ${sfx}`);

    await g.mulai('2-rincian-tugas');
    await p.getByRole('button', { name: new RegExp(`^Nahwu ${sfx}, `) }).first().click();
    await tombol(p, 'Buka pertemuan').waitFor({ timeout: 20000 });

    await g.mulai('3-buka-pertemuan');
    await tombol(p, 'Buka pertemuan').click();
    await tunggu(p, 'Santri · 5 orang');

    await g.mulai('4-perbarui-daftar');
    if (await tombol(p, 'Perbarui daftar').count() === 0) await tombol(p, 'Tindakan lain').click();
    await tombol(p, 'Perbarui daftar').click();
    await tunggu(p, 'Daftar santri sudah sesuai dengan penempatan kelas terbaru.');

    await g.mulai('5-kirim-absensi');
    await p.getByRole('radio', { name: 'Sakit' }).nth(1).click(); // nth(0) = kehadiran guru
    await p.getByPlaceholder('Catatan santri (opsional)').first().fill('Catatan uji jaringan');
    await p.getByPlaceholder('Cari nama atau NIS').fill('zzz'); // saringan tidak boleh mengubah payload
    await tombol(p, 'Konfirmasi dan kirim').click();
    await tunggu(p, 'Absensi berhasil disimpan');

    await g.mulai('6-koreksi');
    await p.getByPlaceholder('Cari nama atau NIS').fill('');
    await p.getByRole('radio', { name: 'Izin' }).nth(2).click();
    await p.getByPlaceholder('Wajib diisi untuk menyimpan koreksi').fill('Koreksi uji jaringan');
    await tombol(p, 'Simpan koreksi').click();
    await tunggu(p, 'Absensi berhasil disimpan');

    await g.mulai('7-laporan');
    await ke(p, '/reports');
    await tunggu(p, 'Ringkasan jadwal');
    await tunggu(p, 'Detail kehadiran');

    await g.mulai('8-rincian-laporan');
    await p.getByRole('button', { name: /^Buka detail pertemuan / }).first().click();
    await tunggu(p, 'Peserta (5/5)');

    await g.mulai('9-jadwal-rincian-selesai');
    await ke(p, `/schedule/${JG}?date=${T}`);
    await tombol(p, 'Buka absensi tersimpan').waitFor({ timeout: 20000 });
    await g.tutup();
  }

  if (bagian === 'izin') {
    // --- V2: pengurus mengajukan tiga izin (routing murobi santri ini ambigu →
    // antrean admin); admin menetapkan murobi untuk ketiganya; murobi
    // menyetujui satu dan menolak satu.
    const pg = await sesi('sbx_pengurus_a');
    let p = pg.page;
    const PENGAJUAN = [
      ['a', '2027-12-01', '2027-12-02', 'Uji jaringan satu'],
      ['b', '2027-12-10', '2027-12-10', 'Uji jaringan dua'],
      ['c', '2027-12-20', '2027-12-21', 'Uji jaringan tiga'],
    ];
    for (const [n, dari, sampai, alasan] of PENGAJUAN) {
      await pg.mulai(`1${n}-perizinan`);
      await ke(p, '/perizinan');
      await tombol(p, 'Ajukan izin baru').waitFor({ timeout: 20000 });
      await pg.mulai(`2${n}-buat-pilih-santri`);
      await tombol(p, 'Ajukan izin baru').click();
      await p.getByRole('radio', { name: /SBX Santri A1/ }).first().waitFor({ timeout: 20000 });
      await p.getByRole('radio', { name: /SBX Santri A1/ }).first().click();
      await tombol(p, 'Lanjut isi data izin').click();
      await pg.mulai(`3${n}-buat-kirim`);
      await p.getByLabel('Mulai', { exact: true }).fill(dari);
      await p.getByLabel('Kembali', { exact: true }).fill(sampai);
      await p.getByPlaceholder('Minimal 3 karakter').fill(alasan);
      await tombol(p, 'Tinjau pengajuan').click();
      await tombol(p, 'Kirim pengajuan').click();
      await tunggu(p, 'Riwayat perubahan');
    }
    await pg.tutup();

    const ad = await sesi('sbx_admin');
    p = ad.page;
    for (const [n, , , alasan] of PENGAJUAN) {
      await ad.mulai(`1${n}-antrean-admin`);
      await ke(p, '/perizinan');
      await p.getByPlaceholder('Cari santri, NIS, atau alasan').fill(alasan);
      await tombol(p, 'Terapkan pencarian').click();
      await tunggu(p, alasan);
      await ad.mulai(`2${n}-rincian-admin`);
      await p.getByRole('button', { name: /^Pengajuan \d+ untuk / }).filter({ hasText: alasan }).first().click();
      await tombol(p, 'Tetapkan murobi').waitFor({ timeout: 20000 });
      await ad.mulai(`3${n}-tetapkan-murobi`);
      await p.getByPlaceholder('Wajib diisi').last().fill('Penetapan uji jaringan');
      await tombol(p, 'Tetapkan murobi').click();
      await tunggu(p, 'Murobi tujuan ditetapkan');
    }
    await ad.tutup();

    const mu = await sesi('sbx_murobi_a');
    p = mu.page;
    for (const [n, alasanCari, keputusan, pesan] of [['a', 'Uji jaringan satu', 'Setujui', 'Keputusan Disetujui tersimpan'], ['b', 'Uji jaringan dua', 'Tolak', 'Keputusan Ditolak tersimpan']]) {
      await mu.mulai(`1${n}-antrean`);
      await ke(p, '/perizinan');
      await p.getByPlaceholder('Cari santri, NIS, atau alasan').fill(alasanCari);
      await tombol(p, 'Terapkan pencarian').click();
      await tunggu(p, alasanCari);
      await mu.mulai(`2${n}-rincian`);
      await p.getByRole('button', { name: /^Pengajuan \d+ untuk / }).filter({ hasText: alasanCari }).first().click();
      await tombol(p, keputusan).waitFor({ timeout: 20000 });
      await mu.mulai(`3${n}-putuskan`);
      const alasan = p.getByPlaceholder('Minimal 3 karakter').first();
      let kirim = tombol(p, keputusan);
      if (!(await alasan.isVisible())) {
        await kirim.click();
        kirim = tombol(p, keputusan === 'Setujui' ? 'Konfirmasi setujui' : 'Konfirmasi tolak');
      }
      await alasan.fill(`Alasan ${keputusan.toLowerCase()} uji`);
      await kirim.click();
      await tunggu(p, pesan);
    }
    await mu.mulai('4-laporan-izin');
    await ke(p, '/izin/laporan?mode=murobi');
    await tunggu(p, 'Durasi keputusan');
    await mu.tutup();

    // --- V7: wali mengajukan → pembimbing minta perbaikan → wali memperbaiki → pembimbing meneruskan.
    const wa = await sesi('sbx_v7_wali_a');
    p = wa.page;
    await wa.mulai('1-pintu-daftar');
    await ke(p, '/perizinan');
    await p.getByRole('button', { name: 'Buka izin orang tua melalui pembimbing' }).click();
    await tunggu(p, 'Izin anak melalui pembimbing');
    await wa.mulai('2-buat-buka');
    await p.getByRole('button', { name: /^Ajukan izin(?: baru)?$/ }).click();
    await tunggu(p, 'Rentang izin');
    await wa.mulai('3-buat-kirim');
    await p.getByRole('radio', { name: 'SBX V7 Santri Satu (V7S0001)' }).click();
    await p.getByLabel('Tanggal izin').fill('2027-11-02');
    await p.getByLabel('Tanggal kembali').fill('2027-11-03');
    await p.getByLabel('Alasan izin').fill('Acara keluarga uji jaringan');
    await p.getByLabel('Catatan', { exact: true }).fill('Dijemput ayah');
    await tombol(p, 'Tinjau pengajuan').click();
    await tunggu(p, 'Tinjau sebelum mengirim');
    await tombol(p, 'Kirim pengajuan').click();
    await tunggu(p, 'Menunggu tinjauan pembimbing');
    const idWali = Number(new URL(p.url()).pathname.split('/').pop());
    await wa.tenang();

    const pb = await sesi('sbx_v7_pembimbing_satu');
    const q = pb.page;
    await pb.mulai('1-antrean');
    await ke(q, '/izin-wali');
    await q.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${idWali}` }).waitFor({ timeout: 20000 });
    await pb.mulai('2-rincian');
    await q.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${idWali}` }).click();
    await tunggu(q, 'Tinjauan pembimbing');
    await pb.mulai('3-minta-perbaikan');
    await q.getByRole('radio', { name: 'Minta perbaikan' }).click();
    await q.getByLabel('Catatan perbaikan untuk wali').fill('Mohon perjelas jam penjemputan');
    await tombol(q, 'Minta perbaikan').click();
    await tunggu(q, 'Permintaan perbaikan terkirim');
    await pb.tenang();

    await wa.mulai('4-perbaiki-buka');
    await ke(p, `/izin-wali/${idWali}`);
    await tunggu(p, 'Mohon perjelas jam penjemputan');
    await tombol(p, 'Perbaiki dan kirim ulang').click();
    await tunggu(p, 'Permintaan pembimbing');
    await wa.mulai('5-perbaiki-kirim');
    await p.getByLabel('Alasan izin').fill('Acara keluarga uji jaringan, dijemput 15.00');
    await tombol(p, 'Kirim perbaikan').click();
    await tunggu(p, 'Menunggu tinjauan pembimbing');
    await wa.tutup();

    await pb.mulai('4-teruskan');
    await ke(q, `/izin-wali/${idWali}`);
    await tunggu(q, 'Teruskan ke murobi');
    await tombol(q, 'Teruskan ke murobi').click();
    await tunggu(q, 'Pengajuan diteruskan');
    await pb.mulai('5-riwayat');
    await ke(q, '/izin-wali');
    await q.getByRole('radio', { name: 'Sudah ditinjau' }).click();
    await q.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${idWali}` }).waitFor({ timeout: 20000 });
    await pb.tutup();

    // --- Notifikasi: murobi membuka satu notifikasi (tandai dibaca) lalu menandai semua.
    const mn = await sesi('sbx_murobi_a');
    p = mn.page;
    await mn.mulai('5-notifikasi-daftar');
    await p.getByRole('button', { name: /^Notifikasi/ }).first().click();
    await p.getByRole('radio', { name: /^Belum dibaca/ }).waitFor({ timeout: 20000 });
    await mn.mulai('6-notifikasi-buka');
    await p.getByRole('button', { name: /Belum dibaca$/ }).first().click();
    await tunggu(p, 'Notifikasi hanya memberi pemberitahuan');
    await mn.mulai('7-notifikasi-tandai-semua');
    await ke(p, '/notifikasi');
    await p.getByRole('radio', { name: /^Belum dibaca/ }).waitFor({ timeout: 20000 });
    if (await tombol(p, 'Tandai semua dibaca').count() === 0) await tombol(p, 'Tindakan lain').click();
    await tombol(p, 'Tandai semua dibaca').click();
    await mn.tenang();
    await mn.mulai('8-perangkat');
    await ke(p, '/notifikasi/perangkat');
    await tunggu(p, 'Seluruh perangkat akun ini');
    await mn.tutup();
  }
} finally {
  await browser.close();
}

fs.writeFileSync(keluaran, JSON.stringify(hasil, null, 1));
const jumlah = Object.values(hasil).reduce((n, v) => n + v.length, 0);
console.log(`Direkam ${Object.keys(hasil).length} langkah, ${jumlah} permintaan → ${keluaran}`);
console.log(`Permintaan ke host selain 127.0.0.1 (diblokir): ${luar.length}${luar.length ? ' ' + [...new Set(luar)].join(', ') : ''}`);
process.exit(luar.length === 0 ? 0 : 1);
