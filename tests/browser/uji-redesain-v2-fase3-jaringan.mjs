import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 3 (Keuangan/BSA) — rekam
// permintaan API untuk alur yang sama pada baseline dan kode fase, lalu
// bandingkan. Pemilih yang dipakai ada di kedua versi; rute yang tombolnya
// pindah ke ⋯ dibuka langsung dengan alamat yang sama pada kedua versi.
//
// Yang direkam per langkah: metode, path lengkap termasuk ID (DB dipulihkan
// dari dump yang sama, jadi ID sama), query (kunci = nilai), dan badan JSON
// kanonik. Password disamarkan; kunci idempotensi (acak per percobaan) diganti
// penanda, tetapi jumlah kunci berbeda per langkah dicatat agar ketukan ganda
// yang menghasilkan dua kunci terlihat. Token tidak pernah ditulis.
//
//   DB_NAME=test_*_test RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8962 \
//     node uji-redesain-v2-fase3-jaringan.mjs rekam <keluaran.json>
//   node uji-redesain-v2-fase3-jaringan.mjs banding <baseline.json> <sesudah.json>
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

const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
const keluaran = a;
if (mode !== 'rekam' || !keluaran || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) {
  throw Error('Pemakaian: rekam <keluaran.json>, hanya 127.0.0.1 dan DB test_*_test');
}
const PASS = 'Sandbox#123';
const hasil = {};
const kunciLangkah = {};
const luar = [];
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sid = nis => Number(sql(`SELECT id FROM santri WHERE nis='${nis}'`));
const kini = new Date();
const B = `${kini.getFullYear()}-${String(kini.getMonth() + 1).padStart(2, '0')}`;
const browser = await chromium.launch({ headless: true });

function kanonik(nilai, kunci = '') {
  if (/idempotency/i.test(kunci)) return '<kunci>';
  if (/^(password|password_.*|.*_password)$/i.test(kunci)) return '<sandi>';
  if (Array.isArray(nilai)) return nilai.map(v => kanonik(v));
  if (nilai && typeof nilai === 'object') return Object.fromEntries(Object.keys(nilai).sort().map(k => [k, kanonik(nilai[k], k)]));
  return nilai;
}

async function sesi(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta', serviceWorkers: 'block' });
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
  return { page, mulai, tutup: async () => { await tenang(); await ctx.close(); } };
}
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const ke = async (p, jalur) => { await p.goto(app + jalur); };

const sSmp = sid('V6S0001');
let gagal = 0;
try {
  const g = await sesi('sbx_v6_bsa_b');
  const p = g.page;

  await g.mulai('01-menu');
  await ke(p, '/keuangan');
  await tombol(p, 'Santri, saldo, dan tagihan').waitFor({ timeout: 20000 });

  await g.mulai('02-daftar-santri-cari');
  await tombol(p, 'Santri, saldo, dan tagihan').click();
  await p.getByLabel('Cari nama atau NIS').fill('V6S0001');
  await tombol(p, 'Cari').click();
  await p.getByRole('button', { name: 'Buka keuangan SBX V6 Santri SMP' }).waitFor({ timeout: 20000 });

  await g.mulai('03-rincian-santri');
  await p.getByRole('button', { name: 'Buka keuangan SBX V6 Santri SMP' }).click();
  await tunggu(p, 'Riwayat tabungan');

  await g.mulai('04-setoran-tunai-ketukan-ganda');
  await tombol(p, 'Setoran').click();
  await tunggu(p, 'Saldo saat ini');
  await p.getByLabel('Nominal (rupiah)').fill('50.000');
  await tombol(p, 'Catat setoran').dblclick();
  await tunggu(p, 'Saldo kini');

  await g.mulai('05-setoran-transfer');
  await p.getByRole('radio', { name: 'Transfer' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('20.000');
  await p.getByLabel('Referensi transfer (wajib)').fill('NET-REF-1');
  await tombol(p, 'Catat setoran').click();
  await tunggu(p, 'masuk antrean verifikasi');

  await g.mulai('06-verifikasi-setoran-ketukan-ganda');
  await ke(p, '/keuangan/verifikasi');
  await tombol(p, 'Sudah diperiksa — verifikasi').dblclick();
  await tunggu(p, 'Setoran transfer diverifikasi');

  await g.mulai('06a-penarikan-tunai');
  await ke(p, `/keuangan/transaksi?santri_id=${sSmp}&mode=keluar`);
  await p.getByRole('radio', { name: 'Penarikan', exact: true }).click();
  await p.getByLabel('Nominal (rupiah)').fill('5000');
  await p.getByLabel('Tujuan (wajib)').fill('Uji penarikan');
  await tombol(p, 'Catat penarikan').dblclick();
  await tunggu(p, 'Penarikan Rp5.000 tercatat sah');

  await g.mulai('06b-belanja-transfer');
  await p.getByRole('radio', { name: 'Belanja', exact: true }).click();
  await p.getByRole('radio', { name: 'Transfer', exact: true }).click();
  await p.getByLabel('Nominal (rupiah)').fill('6000');
  await p.getByLabel('Tujuan (wajib)').fill('Uji belanja');
  await p.getByLabel('Referensi transfer (wajib)').fill('NET-BELANJA');
  await tombol(p, 'Catat belanja').dblclick();
  await tunggu(p, 'Belanja Rp6.000 tercatat sah');

  await g.mulai('06c-setoran-transfer-untuk-ditolak');
  await ke(p, `/keuangan/transaksi?santri_id=${sSmp}`);
  await p.getByRole('radio', { name: 'Transfer' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('4000');
  await p.getByLabel('Referensi transfer (wajib)').fill('NET-TOLAK-SETOR');
  await tombol(p, 'Catat setoran').dblclick();
  await tunggu(p, 'masuk antrean verifikasi');

  await g.mulai('06d-tolak-setoran-transfer');
  await ke(p, '/keuangan/verifikasi');
  await tombol(p, 'Tolak').click();
  await p.getByLabel('Alasan penolakan (wajib)').fill('Uji penolakan setoran');
  await tombol(p, 'Tolak setoran ini').dblclick();
  await tunggu(p, 'Setoran transfer ditolak');

  await g.mulai('07-terbit-satu-santri-ketukan-ganda');
  await ke(p, `/keuangan/terbit-santri?santri_id=${sSmp}`);
  await tombol(p, 'Tampilkan tagihan').click();
  await p.getByRole('button', { name: /^Terbitkan tagihan Rp/ }).dblclick();
  await p.waitForURL(u => /^\/keuangan\/tagihan\/\d+$/.test(u.pathname), { timeout: 20000 });
  const T = Number(sql(`SELECT id FROM bsa_tagihan WHERE santri_id=${sSmp} AND periode='${B}'`));

  await g.mulai('08-bayar-tunai-ketukan-ganda');
  await tombol(p, 'Catat pembayaran tunai/transfer').click();
  await p.getByLabel('Nominal (rupiah)').fill('20000');
  await tombol(p, 'Catat pembayaran').dblclick();
  await tunggu(p, 'Sisa sesudah pembayaran');

  await g.mulai('09-bayar-transfer-menunggu');
  await ke(p, `/keuangan/bayar?tagihan_id=${T}`);
  await p.getByRole('radio', { name: 'Transfer' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('10000');
  await p.getByLabel('Referensi transfer (wajib)').fill('NET-REF-2');
  await tombol(p, 'Catat pembayaran').click();
  await tombol(p, 'Dana sudah diperiksa — verifikasi').waitFor({ timeout: 20000 });

  await g.mulai('10-verifikasi-pembayaran');
  await tombol(p, 'Dana sudah diperiksa — verifikasi').click();
  await tunggu(p, 'Pembayaran diverifikasi');

  await g.mulai('10a-bayar-transfer-untuk-ditolak');
  await ke(p, `/keuangan/bayar?tagihan_id=${T}`);
  await p.getByRole('radio', { name: 'Transfer' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('7000');
  await p.getByLabel('Referensi transfer (wajib)').fill('NET-TOLAK-BAYAR');
  await tombol(p, 'Catat pembayaran').dblclick();
  await tombol(p, 'Tolak transfer…').waitFor({ timeout: 20000 });

  await g.mulai('10b-tolak-pembayaran-transfer');
  await tombol(p, 'Tolak transfer…').click();
  await p.getByLabel('Alasan penolakan (wajib)').fill('Uji penolakan pembayaran');
  await tombol(p, 'Tolak transfer').dblclick();
  await tunggu(p, 'Pembayaran dibatalkan');

  const pengeluaran = Number(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp} AND tujuan IN ('Uji penarikan', 'Uji belanja')`));
  const ditolak = Number(sql("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE referensi='NET-TOLAK-SETOR' AND status='Ditolak'"));
  const batal = Number(sql("SELECT COUNT(*) FROM bsa_pembayaran WHERE referensi='NET-TOLAK-BAYAR' AND status='Dibatalkan'"));
  if (pengeluaran !== 2 || ditolak !== 1 || batal !== 1) throw Error('Hasil DB penarikan, belanja, atau penolakan tidak sesuai');
  console.log('[lulus] DB: dua pengeluaran, satu penolakan setoran, satu penolakan pembayaran');

  await g.mulai('11-bayar-dari-tabungan-ketukan-ganda');
  await ke(p, `/keuangan/tagihan/${T}`);
  // Baseline: tombol di bilah. TL-11: di menu ⋯ (membuka menu tanpa permintaan).
  await tunggu(p, 'Rincian biaya');
  if (await p.getByRole('button', { name: 'Bayar dari tabungan', exact: true }).count() === 0) await tombol(p, 'Tindakan lain').click();
  await tombol(p, 'Bayar dari tabungan').click();
  await p.getByLabel('Nominal dipotong dari tabungan').fill('30.000');
  await p.getByRole('checkbox').click();
  await tombol(p, 'Bayar dari tabungan').dblclick();
  await tunggu(p, 'Dari tabungan santri');

  await g.mulai('12-batal-pembayaran');
  await tombol(p, 'Batalkan pembayaran…').click();
  await p.getByLabel('Alasan pembatalan (wajib)').fill('Uji jaringan batal');
  await tombol(p, 'Batalkan pembayaran').click();
  await tunggu(p, 'saldo tabungan kembali');

  await g.mulai('13-revisi-tagihan');
  await ke(p, `/keuangan/ubah-tagihan?tagihan_id=${T}`);
  await tunggu(p, 'versi 1');
  await p.getByRole('radio', { name: 'Tambahan', exact: true }).click();
  await p.getByLabel('Nominal (rupiah)').fill('5000');
  await p.getByLabel('Alasan (wajib)').fill('Uji jaringan revisi');
  await tombol(p, 'Simpan revisi').click();
  await p.waitForTimeout(1500);

  await g.mulai('14-koreksi-tabungan');
  const X = Number(sql(`SELECT id FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp} AND jenis='Setoran' AND metode='Tunai' ORDER BY id LIMIT 1`));
  await ke(p, `/keuangan/tabungan/${X}`);
  await tombol(p, 'Koreksi transaksi…').click();
  await p.getByLabel('Alasan koreksi (wajib)').fill('Uji jaringan koreksi');
  await tombol(p, 'Catat koreksi').click();
  await tunggu(p, 'tercatat. Saldo kini');

  await g.mulai('15-penerbitan-massal-ketukan-ganda');
  await ke(p, '/keuangan/penerbitan');
  await p.getByLabel('Periode (TTTT-BB)').fill(B);
  await p.getByLabel('Jatuh tempo', { exact: true }).fill(`${B}-15`);
  await tombol(p, 'Lihat pratinjau').click();
  await tunggu(p, 'Pratinjau ');
  await p.getByRole('checkbox').click();
  await tombol(p, 'Terbitkan tagihan').dblclick();
  await tunggu(p, 'diterbitkan');

  await g.mulai('16-batal-tagihan');
  const TB = Number(sql(`SELECT id FROM bsa_tagihan WHERE periode='${B}' AND santri_id<>${sSmp} AND terbayar=0 AND status='Terbit' ORDER BY id LIMIT 1`));
  await ke(p, `/keuangan/ubah-tagihan?tagihan_id=${TB}`);
  await tunggu(p, 'versi 1');
  await p.getByRole('radio', { name: 'Batalkan tagihan', exact: true }).click();
  await p.getByLabel('Alasan (wajib)').fill('Uji jaringan batal tagihan');
  await tombol(p, 'Batalkan tagihan').click();
  await p.waitForTimeout(1500);

  await g.mulai('17-tunggakan');
  await ke(p, '/keuangan/tunggakan');
  await p.getByRole('radio', { name: 'Lewat jatuh tempo' }).click();

  await g.mulai('18-pembayaran-saring');
  await ke(p, '/keuangan/pembayaran');
  await p.getByRole('radio', { name: 'Tunai' }).click();

  await g.mulai('19-mutasi-saring');
  await ke(p, '/keuangan/tabungan');
  await p.getByRole('radio', { name: 'Setoran' }).click();

  await g.mulai('20-rekonsiliasi');
  await ke(p, '/keuangan/rekonsiliasi');
  await tombol(p, 'Tampilkan').click();
  await tunggu(p, 'Penerimaan kas baru');

  await g.mulai('21-laporan');
  await ke(p, '/keuangan/laporan');
  await tunggu(p, 'Saldo akhir');
  await p.getByRole('radio', { name: '3 bulan' }).click();
  await tunggu(p, 'Saldo akhir');
  await p.getByRole('radio', { name: 'Tahun ini' }).click();
  await tunggu(p, 'Saldo akhir');
  await g.tutup();

  const ad = await sesi('sbx_admin');
  await ad.mulai('01-menu');
  await ke(ad.page, '/keuangan');
  await tunggu(ad.page, 'Admin — hanya membaca');
  await ad.mulai('02-rincian-santri');
  await ke(ad.page, `/keuangan/santri/${sSmp}`);
  await tunggu(ad.page, 'Riwayat tabungan');
  await ad.mulai('03-verifikasi');
  await ke(ad.page, '/keuangan/verifikasi');
  await tunggu(ad.page, 'Setoran tabungan');
  await ad.tutup();

  const w = await sesi('sbx_v6_wali_dua');
  await w.mulai('01-menu');
  await ke(w.page, '/keuangan');
  await tunggu(w.page, 'Keuangan anak');
  await w.mulai('02-rincian-anak');
  await w.page.getByRole('button', { name: 'Buka keuangan SBX V6 Santri SMP' }).click();
  await tunggu(w.page, 'Riwayat tabungan');
  await w.mulai('03-tagihan-anak');
  await ke(w.page, `/keuangan/tagihan/${T}`);
  await tunggu(w.page, 'Rincian biaya');
  await w.mulai('04-riwayat-pembayaran');
  await ke(w.page, '/keuangan/pembayaran');
  await w.mulai('05-laporan-anak');
  await ke(w.page, '/keuangan/laporan');
  await tunggu(w.page, 'Saldo akhir');
  await w.tutup();
} catch (e) {
  gagal++;
  console.log('[gagal] Perekaman berhenti: ' + (e?.stack || e));
} finally {
  await browser.close();
}
// Ketukan ganda: tiap langkah mutasi tepat satu kunci idempotensi (satu operasi).
for (const [nama, set] of Object.entries(kunciLangkah)) {
  const post = (hasil[nama] ?? []).filter(x => x.startsWith('POST ') && x.includes('<kunci>')).length;
  const satu = set.size === 1 && post === 1;
  console.log(`${satu ? '[lulus]' : '[gagal]'} ${nama}: ${set.size} kunci idempotensi, ${post} POST berkunci`);
  if (!satu) gagal++;
}
fs.writeFileSync(keluaran, JSON.stringify(hasil, null, 1));
const total = Object.values(hasil).reduce((n, x) => n + x.length, 0);
console.log(`Direkam ${Object.keys(hasil).length} langkah, ${total} permintaan; keluar 127.0.0.1: ${luar.length}`);
if (luar.length) gagal++;
process.exit(gagal ? 1 : 0);
