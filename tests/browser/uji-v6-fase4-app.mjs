import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi PRD V6 Fase 4 — layar Keuangan (Expo WEB dari repo alhasanApps + API/DB uji nyata).
//
//   V6F4_DB=test_v6_f4_app_test bash tests/v6_phase4_db.sh
//   DB_NAME=test_v6_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8984 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env.local> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8984/api/v1 npx expo start --web --port 8099 --clear &)
//   (cd tests/browser && DB_NAME=test_v6_f4_app_test V6_WEB=http://127.0.0.1:8984 V6_APP=http://127.0.0.1:8099 OUT_DIR=… node uji-v6-fase4-app.mjs)
//
//   K-A  petugas: setoran dari aplikasi, ketukan ganda → satu POST; jawaban hilang lalu
//        kirim ulang → kunci sama, replay, satu kredit; paritas angka dengan website;
//   K-B  belanja, melebihi saldo (pesan server), setoran transfer → antrean, verifikasi;
//   K-C  bayar dari tabungan (wajib centang) dan pembayaran tunai; kuitansi; paritas web;
//   K-D  konflik versi revisi tagihan (409 → "Muat versi terbaru"), penerbitan massal;
//   K-E  admin hanya membaca; rekonsiliasi = API;
//   K-F  wali dua anak: dua kartu terpisah = API; notifikasi → tombol → tagihan; wali lain
//        dengan URL anak lain → ditolak tanpa data;
//   K-G  relasi/penugasan dicabut saat layar terbuka; sesi berakhir → masuk ulang;
//        offline → pesan jelas, lalu pulih;
//   K-H  local/sessionStorage dan konsol tanpa referensi/token; nol permintaan keluar
//        127.0.0.1; 375 px tanpa gulir samping.
//
// Mengubah DB uji. Ini Expo WEB (Chromium 375×812), bukan perangkat native.
const web = process.env.V6_WEB, app = process.env.V6_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/v6f4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const REF = 'REF-APP-RAHASIA';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v6-f4-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, {
    method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const rp = n => `Rp${String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
const kunci = p => `app-uji-${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const sSmp = Number(sql("SELECT id FROM santri WHERE nis='V6S0001'"));
const sTs = Number(sql("SELECT id FROM santri WHERE nis='V6S0002'"));
const saldo = s => Number(sql(`SELECT COALESCE(MAX(saldo),0) FROM bsa_tabungan_akun WHERE santri_id=${s}`));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postBsa = [];
// Penanda jaringan putus: penangan rute Playwright memanggil server sendiri, jadi
// `context.setOffline` tidak memutus API; permintaan API digagalkan di sini.
const jaringan = { putus: false };
async function halamanApp(user, opsi = {}) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (jaringan.putus) return route.abort('internetdisconnected');
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (route.request().method() === 'POST' && u.pathname.startsWith('/api/v1/v6/')) postBsa.push({ user, path: u.pathname, body: route.request().postDataJSON() });
    if (opsi.buangJawaban?.(u, route.request())) {
      await route.fetch(); // server memproses, jawaban dibuang (jaringan putus sesudah kirim)
      return route.abort('connectionreset');
    }
    const response = await route.fetch();
    await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login');
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const tombol = (p, name) => p.getByRole('button', { name, exact: true });
const tunggu = (p, t, timeout = 15000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const lebar = async (p, nama) => {
  const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(lebih <= 1, `${nama}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
};
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));

try {
  console.log('\n=== K-A. Petugas: setoran dari aplikasi, ketukan ganda, jawaban hilang ===');
  const awalLedger = Number(sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi'));
  ok(awalLedger === 0, 'DB uji bersih (ledger kosong)');
  let buang = 1;
  const p = await halamanApp('sbx_v6_bsa_b', { buangJawaban: (u, r) => r.method() === 'POST' && u.pathname.endsWith('/setoran') && buang-- > 0 });
  await tombol(p, 'Keuangan').click();
  await tombol(p, 'Santri, saldo, dan tagihan').click();
  await p.getByLabel('Cari nama atau NIS').fill('V6S0001');
  await tombol(p, 'Cari').click();
  await p.getByRole('button', { name: 'Buka keuangan SBX V6 Santri SMP' }).click();
  await tunggu(p, 'Saldo tabungan');
  await lebar(p, 'Rincian keuangan santri (petugas)');
  await tombol(p, 'Setoran').click();
  await p.getByLabel('Nominal (rupiah)').fill('100.000');
  const sebelumPost = postBsa.length;
  await tombol(p, 'Catat setoran').dblclick();
  await tunggu(p, 'offline atau server tidak terjangkau');
  ok(postBsa.length - sebelumPost === 1, `Ketukan ganda "Catat setoran" → satu POST (terhitung ${postBsa.length - sebelumPost}); jawaban hilang tampil sebagai pesan jaringan`);
  ok(saldo(sSmp) === 100000, 'Server sudah menyimpan setoran pertama walau jawaban hilang (saldo Rp100.000)');
  await tombol(p, 'Catat setoran').click();
  await tunggu(p, 'sudah tercatat sebelumnya');
  const dua = postBsa.slice(-2);
  ok(dua.length === 2 && dua[0].body.idempotency_key === dua[1].body.idempotency_key, 'Kirim ulang memakai kunci idempotensi yang SAMA');
  ok(Number(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp}`)) === 1 && saldo(sSmp) === 100000, 'Tepat satu kredit Rp100.000 (tidak digandakan)');
  await lebar(p, 'Formulir transaksi');
  await foto(p, 'setoran-petugas');
  const kw = sql(`SELECT id FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp}`);
  const nomor = `BSA-TB-${new Date().getFullYear()}-${String(kw).padStart(6, '0')}`;

  console.log('\n=== K-B. Belanja, melebihi saldo, transfer menunggu, verifikasi ===');
  await tombol(p, 'Kembali ke santri').click();
  await tunggu(p, rp(100000));
  await tombol(p, 'Penarikan/belanja').click();
  await p.getByRole('radio', { name: 'Belanja' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('30000');
  await p.getByLabel('Tujuan (wajib)').fill('Kitab uji aplikasi');
  await tombol(p, 'Catat belanja').click();
  await tunggu(p, 'Saldo kini Rp70.000');
  ok(saldo(sSmp) === 70000, 'Belanja Rp30.000 dari aplikasi → saldo Rp70.000');
  await p.getByLabel('Nominal (rupiah)').fill('999999');
  await p.getByLabel('Tujuan (wajib)').fill('Melebihi saldo uji');
  await tombol(p, 'Catat belanja').click();
  await tunggu(p, 'Saldo tabungan tidak mencukupi');
  ok(saldo(sSmp) === 70000 && (await teks(p)).includes('Periksa kembali isian Anda'), 'Belanja melebihi saldo → pesan server Bahasa Indonesia, saldo tetap');
  await tombol(p, 'Kembali ke santri').click();
  await tombol(p, 'Setoran').click();
  await p.getByRole('radio', { name: 'Transfer' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('20.000');
  await p.getByLabel('Referensi transfer (wajib)').fill(REF);
  await tombol(p, 'Catat setoran').click();
  await tunggu(p, 'masuk antrean verifikasi');
  ok(saldo(sSmp) === 70000 && Number(sql("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE status='Menunggu'")) === 1, 'Setoran transfer tanpa centang → antrean Menunggu, saldo tetap');
  await p.goto(app + '/keuangan/verifikasi');
  await tunggu(p, 'Setoran tabungan (1)');
  const vSebelum = postBsa.length;
  await tombol(p, 'Sudah diperiksa — verifikasi').dblclick();
  await tunggu(p, 'Setoran transfer diverifikasi');
  ok(postBsa.length - vSebelum === 1 && saldo(sSmp) === 90000 && Number(sql("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE jenis='Setoran' AND metode='Transfer'")) === 1,
    'Verifikasi dari aplikasi (ketukan ganda) → satu POST, satu baris ledger, saldo Rp90.000');
  await lebar(p, 'Verifikasi transfer');

  const pw = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const wp = await pw.newPage();
  await wp.route('**/*', r => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.fallback() : r.abort()));
  await wp.goto(`${web}/portal/index.php`);
  await wp.fill('#username', 'sbx_v6_bsa_b');
  await wp.fill('#password', PASS);
  await Promise.all([wp.waitForURL(/\/portal\/index\.php/), wp.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  await wp.goto(`${web}/portal/bsa_santri.php?id=${sSmp}`);
  const baris = await wp.locator('tr', { hasText: nomor }).count();
  const tw = (await wp.locator('body').innerText()).replace(/\s+/g, ' ');
  ok(baris === 1 && tw.includes(rp(90000)) && tw.includes(rp(100000)), `Paritas: website menampilkan ${nomor} satu kali dan saldo ${rp(90000)} yang sama`);

  console.log('\n=== K-C. Tagihan: bayar dari tabungan dan tunai ===');
  const t = await api('sbx_v6_bsa_b', '/v6/bsa/tagihan', { idempotency_key: kunci('t'), santri_id: sSmp, periode: '2026-10', jatuh_tempo: '2026-10-31', komponen: [{ nama: 'Syahriah', nominal: 100000 }] });
  const tid = t.json.data.id;
  await p.goto(app + `/keuangan/tagihan/${tid}`);
  await tunggu(p, 'Tagihan Oktober 2026');
  await lebar(p, 'Rincian tagihan');
  await tombol(p, 'Bayar dari tabungan').click();
  await tunggu(p, 'Saldo tabungan Rp90.000');
  await p.getByLabel('Nominal dipotong dari tabungan').fill('50.000');
  await tombol(p, 'Bayar dari tabungan').click();
  await tunggu(p, 'Centang persetujuan');
  ok(Number(sql(`SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id=${tid}`)) === 0, 'Tanpa centang persetujuan → tidak terkirim, tabungan tidak dipotong');
  await p.getByRole('checkbox').click();
  const bSebelum = postBsa.length;
  await tombol(p, 'Bayar dari tabungan').dblclick();
  await p.waitForURL(u => /\/keuangan\/pembayaran\/\d+/.test(u.pathname));
  await tunggu(p, 'Dari tabungan santri');
  ok(postBsa.length - bSebelum === 1 && saldo(sSmp) === 40000 && Number(sql(`SELECT terbayar FROM bsa_tagihan WHERE id=${tid}`)) === 50000
    && Number(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp} AND jenis='PembayaranTagihan'`)) === 1,
    'Bayar dari tabungan (ketukan ganda) → satu debit + satu pembayaran; saldo Rp40.000, sisa Rp50.000');
  const tk = await teks(p);
  ok(tk.includes('Debit tabungan') && tk.includes('Sisa sesudah pembayaran') && tk.includes(rp(50000)), 'Kuitansi aplikasi: rujukan debit tabungan dan sisa sesudah Rp50.000');
  await lebar(p, 'Kuitansi pembayaran');
  await foto(p, 'kuitansi-tabungan');
  await tombol(p, 'Buka tagihan').click();
  await tombol(p, 'Catat pembayaran tunai/transfer').click();
  await p.getByLabel('Nominal (rupiah)').fill('20000');
  await tombol(p, 'Catat pembayaran').click();
  await p.waitForURL(u => /\/keuangan\/pembayaran\/\d+/.test(u.pathname));
  await tunggu(p, 'Sisa sesudah pembayaran');
  ok(Number(sql(`SELECT terbayar FROM bsa_tagihan WHERE id=${tid}`)) === 70000, 'Pembayaran tunai Rp20.000 dari aplikasi → terbayar Rp70.000');
  await wp.goto(`${web}/portal/bsa_tagihan.php?id=${tid}`);
  const twt = (await wp.locator('body').innerText()).replace(/\s+/g, ' ');
  const kwRows = sql(`SELECT nomor_kuitansi FROM bsa_pembayaran WHERE tagihan_id=${tid}`).split('\n');
  const tiapSekali = await Promise.all(kwRows.map(async k => wp.locator('tr', { hasText: k }).count()));
  ok(tiapSekali.every(c => c === 1) && twt.includes(rp(30000)), `Paritas tagihan: dua kuitansi aplikasi tampil sekali di website, sisa ${rp(30000)}`);

  console.log('\n=== K-D. Konflik versi dan penerbitan ===');
  await p.goto(app + `/keuangan/ubah-tagihan?tagihan_id=${tid}`);
  await tunggu(p, 'versi 1');
  await api('sbx_v6_bsa_tsanawi', '/v6/bsa/akses');
  const rv = await api('sbx_v6_bsa_b', `/v6/bsa/tagihan/${tid}/revisi`, { idempotency_key: kunci('rv'), versi: 1, jatuh_tempo: '2026-11-03', alasan: 'Diubah petugas lain' });
  await p.getByLabel('Alasan (wajib)').fill('Layar lama aplikasi');
  await p.getByRole('radio', { name: 'Tambahan' }).click();
  await p.getByLabel('Nominal (rupiah)').fill('5000');
  await tombol(p, 'Simpan revisi').click();
  await tunggu(p, 'Muat ulang data sebelum mencoba kembali');
  ok(rv.status === 200 && Number(sql(`SELECT versi FROM bsa_tagihan WHERE id=${tid}`)) === 2 && Number(sql(`SELECT total FROM bsa_tagihan WHERE id=${tid}`)) === 100000,
    'Revisi dari layar versi lama → 409 dengan pesan muat ulang; tagihan tidak tertimpa');
  await tombol(p, 'Muat versi terbaru (isian ini dibuang)').click();
  await tunggu(p, 'versi 2');
  ok(true, 'Tombol "Muat versi terbaru" memuat versi 2');
  await api('sbx_v6_bsa_b', '/v6/bsa/tarif', { idempotency_key: kunci('tarif'), unit: 'Tsanawi', berlaku_mulai: '2026-11', komponen: [{ nama: 'Syahriah', nominal: 120000 }], alasan: 'Tarif uji aplikasi' });
  await p.goto(app + '/keuangan/penerbitan');
  await p.getByLabel('Periode (TTTT-BB)').fill('2026-11');
  await p.getByLabel('Jatuh tempo', { exact: true }).fill('2026-11-15');
  await p.getByRole('radio', { name: 'Tsanawi' }).click();
  await tombol(p, 'Lihat pratinjau').click();
  await tunggu(p, 'Pratinjau November 2026');
  await p.getByRole('checkbox').click();
  const mSebelum = postBsa.length;
  await tombol(p, 'Terbitkan tagihan').dblclick();
  await tunggu(p, 'tagihan November 2026 diterbitkan');
  const terbit = Number(sql("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='2026-11'"));
  ok(postBsa.length - mSebelum === 1 && terbit >= 1 && Number(sql("SELECT COUNT(*) FROM bsa_penerbitan")) === 1, `Penerbitan massal dari aplikasi (ketukan ganda) → satu batch, ${terbit} tagihan`);
  await lebar(p, 'Penerbitan');
  await p.context().close();

  console.log('\n=== K-E. Admin hanya membaca; rekonsiliasi = API ===');
  const a = await halamanApp('sbx_admin');
  await tombol(a, 'Keuangan').click();
  await tunggu(a, 'Admin — hanya membaca');
  ok(!(await teks(a)).includes('Terbitkan tagihan bulanan'), 'Admin: tanpa tombol penerbitan');
  await a.goto(app + `/keuangan/santri/${sSmp}`);
  await tunggu(a, 'Riwayat tabungan');
  const ta = await teks(a);
  ok(!ta.includes('Penarikan/belanja') && !ta.includes('Sudah diperiksa — verifikasi') && ta.includes(rp(40000)), 'Admin: rincian santri tanpa tombol mutasi; saldo Rp40.000 sama');
  ok(ta.includes(REF), 'Admin (internal) melihat referensi setoran transfer seperti website (wali tidak — diuji K-F)');
  const rek = (await api('sbx_admin', '/v6/bsa/rekonsiliasi')).json.data;
  await a.goto(app + '/keuangan/rekonsiliasi');
  await tunggu(a, 'Penerimaan kas baru');
  const tr = await teks(a);
  ok(tr.includes(`Penerimaan kas baru ${rp(rek.penerimaan_kas_baru)}`) && tr.includes(`Debit tabungan untuk tagihan ${rp(rek.internal.debit_tabungan)}`) && tr.includes('Selisih Rp0'),
    `Rekonsiliasi aplikasi = API (kas baru ${rp(rek.penerimaan_kas_baru)}, internal ${rp(rek.internal.debit_tabungan)}, selisih Rp0)`);
  await lebar(a, 'Rekonsiliasi');
  await foto(a, 'rekonsiliasi-admin');
  await a.context().close();

  console.log('\n=== K-F. Wali dua anak, notifikasi, wali lain ===');
  const daftarW = (await api('sbx_v6_wali_dua', '/v6/bsa/santri')).json.data.rows;
  const w = await halamanApp('sbx_v6_wali_dua');
  await tombol(w, 'Keuangan anak').click();
  await tunggu(w, 'Keuangan anak');
  const ka = await w.getByRole('button', { name: /^Buka keuangan SBX V6 Santri/ }).count();
  const tw2 = await teks(w);
  ok(ka === 2 && daftarW.every(s => tw2.includes(s.nama) && tw2.includes(rp(s.saldo))), `Wali dua anak: dua kartu terpisah, saldo per anak = API (${daftarW.map(s => rp(s.saldo)).join(', ')})`);
  await lebar(w, 'Keuangan anak');
  await foto(w, 'wali-dua-anak');
  await w.getByRole('button', { name: 'Buka keuangan SBX V6 Santri Tsanawi' }).click();
  await tunggu(w, 'Riwayat tabungan');
  const tTs = await teks(w);
  ok(!tTs.includes('SBX V6 Santri SMP') && !tTs.includes(REF) && !tTs.includes('Transfer menunggu'), 'Rincian anak Tsanawi: tanpa data anak SMP, tanpa referensi atau antrean transfer');
  await w.goto(app + '/notifikasi');
  await tunggu(w, 'Tagihan bulanan');
  await w.getByText('Tagihan bulanan').first().click();
  await tombol(w, 'Buka tagihan').click();
  await w.waitForURL(u => /\/keuangan\/tagihan\/\d+/.test(u.pathname));
  await tunggu(w, 'Rincian biaya');
  const tWt = await teks(w);
  ok(!tWt.includes('Catat pembayaran') && !tWt.includes('Riwayat versi') && !tWt.includes('Salah') , 'Notifikasi → "Buka tagihan" → rincian tagihan anak (tanpa tombol petugas/riwayat internal)');
  ok(!(await penyimpanan(w)).includes(REF) && !(await penyimpanan(w)).includes('SBX V6'), 'Wali: local/sessionStorage tanpa referensi atau nama santri');
  const ws = await halamanApp('sbx_v6_wali_smp');
  await ws.goto(app + `/keuangan/santri/${sTs}`);
  await tunggu(ws, 'Data belum dapat ditampilkan');
  ok(!(await teks(ws)).includes('SBX V6 Santri Tsanawi'), 'Wali SMP membuka URL anak Tsanawi → ditolak tanpa nama/angka');
  await ws.goto(app + `/keuangan/tagihan/${tid}`);
  await tunggu(ws, 'Rincian biaya');
  ok(true, 'Wali SMP membuka tagihan anaknya sendiri → tampil');
  await ws.context().close();

  console.log('\n=== K-G. Pencabutan saat layar terbuka, sesi berakhir, offline ===');
  await w.goto(app + `/keuangan/santri/${sTs}`);
  await tunggu(w, 'Riwayat tabungan');
  const wDua = sql("SELECT id FROM users WHERE username='sbx_v6_wali_dua'");
  sql(`UPDATE santri_wali sw JOIN users u ON u.wali_id = sw.wali_id SET sw.archived_at = NOW() WHERE u.id = ${wDua} AND sw.santri_id = ${sTs}`);
  await tombol(w, 'Muat ulang').click();
  await tunggu(w, 'Data belum dapat ditampilkan');
  ok(!(await teks(w)).includes('Riwayat tabungan'), 'Relasi dicabut saat layar anak terbuka → muat ulang ditolak, data tidak tampil');
  await w.goto(app + '/keuangan');
  await tunggu(w, 'Keuangan anak');
  ok((await w.getByRole('button', { name: /^Buka keuangan SBX V6 Santri/ }).count()) === 1, 'Pintu keuangan wali kini hanya satu anak');
  sql(`UPDATE santri_wali sw JOIN users u ON u.wali_id = sw.wali_id SET sw.archived_at = NULL WHERE u.id = ${wDua} AND sw.santri_id = ${sTs}`);
  jaringan.putus = true;
  await tombol(w, 'Muat ulang').click().catch(() => {});
  await tunggu(w, 'offline atau server tidak terjangkau', 20000);
  ok(true, 'Offline → pesan "Perangkat sedang offline…" dengan tombol coba lagi');
  jaringan.putus = false;
  await tombol(w, 'Coba lagi').click();
  await tunggu(w, 'Keuangan anak');
  ok(true, 'Kembali online → coba lagi memuat data');
  sql(`UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ${wDua} AND revoked_at IS NULL`);
  await tombol(w, 'Muat ulang').click();
  await w.waitForURL(u => u.pathname === '/login', { timeout: 15000 }).catch(() => {});
  ok(new URL(w.url()).pathname === '/login', 'Token dicabut/kedaluwarsa → aplikasi kembali ke layar masuk');
  await w.context().close();

  const p2 = await halamanApp('sbx_v6_bsa_b');
  await p2.goto(app + `/keuangan/transaksi?santri_id=${sSmp}&mode=setoran`);
  await tunggu(p2, 'Saldo saat ini');
  await p2.getByLabel('Nominal (rupiah)').fill('5000');
  const tugas = sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id = b.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
  sql(`UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = ${tugas}`);
  const saldoSebelum = saldo(sSmp);
  await tombol(p2, 'Catat setoran').click();
  await tunggu(p2, 'Informasi tidak dapat diakses');
  ok(saldo(sSmp) === saldoSebelum, 'Penugasan dinonaktifkan saat formulir terbuka → kirim ditolak 403 berpesan jelas, tanpa mutasi');
  sql(`UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = ${tugas}`);
  ok(!(await penyimpanan(p2)).includes(REF) && !(await penyimpanan(p2)).includes('SBX V6'), 'Petugas: local/sessionStorage tanpa referensi atau nama santri');
  await p2.context().close();

  console.log('\n=== K-H. Konsol dan jaringan ===');
  const semuaTokens = Object.values(tokens);
  ok(!konsol.some(l => l.includes(REF) || semuaTokens.some(t => l.includes(t)) || l.includes(PASS)), `Konsol (${konsol.length} baris) tanpa referensi transfer, token, atau kata sandi`);
  ok(permintaanLuar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${permintaanLuar.length})`);
  await pw.close();
} catch (e) {
  gagal++;
  console.log('[gagal] Pengecualian: ' + (e?.stack || e));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
