import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 3 — Keuangan/BSA (Expo WEB dari
// salinan alhasanApps tanpa .env* + API/DB uji nyata, Chromium).
//
//   DB_NAME=test_*_test RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8962 OUT_DIR=… node uji-redesain-v2-fase3.mjs
//   (DB: tests/v6_phase5_db.sh + fixture worker Fase 5 + v6_tagihan_satu_santri_seed.php; lihat perintah-uji.sh)
//
//   A  K-7/K-10: 19 varian rute dari 18 layar Keuangan dibuka LANGSUNG (tanpa riwayat):
//      judul kepala, tepat satu ← ≥ 44 pt → Beranda; tanpa tombol Bagikan/Cetak; tanpa
//      "pendapatan"/"laba"; rupiah selalu bertitik; isi tanpa luapan pada 375 px;
//   B  ← dengan riwayat kembali ke layar sebelumnya;
//   C  menu per peran (petugas 8 ubin, admin 7 ubin hanya baca, orang tua per anak
//      = API); kepala profil santri: strip Saldo · Sisa tagihan · Transfer menunggu = API,
//      orang tua tanpa "Transfer menunggu"; tab segmen; mutasi bertanda = arah API;
//      kuitansi Menunggu = jam, Sah = centang; laporan: grafik hanya bila seri ≥ 2 bulan;
//   D  target sentuh ≥ 44 pt pada kontrol yang terlihat;
//   E  768/1280 px dan mode gelap tanpa luapan (petugas dan orang tua).
//
// Ini Expo WEB, bukan perangkat native; skala teks sistem diuji di emulator/simulator.
const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/rtv2f3-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const rp = n => `${n < 0 ? '−' : ''}Rp${String(Math.abs(Math.trunc(n))).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-rtv2-f3' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const kunci = p => `rtv2f3-${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

const browser = await chromium.launch({ headless: true });
const luar = [];
const galatHalaman = [];
async function masuk(user, { lebar = 375, tinggi = 812, skema = 'light' } = {}) {
  const ctx = await browser.newContext({ viewport: { width: lebar, height: tinggi }, colorScheme: skema, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  page.on('pageerror', e => galatHalaman.push(`${user}: ${e.message}`));
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
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 60000 });
  await page.waitForTimeout(1200);
  return page;
}
const terlihat = l => l.filter({ visible: true });
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const jalur = p => new URL(p.url()).pathname;
const tombol = (p, nama) => terlihat(p.getByRole('button', { name: nama, exact: true }));
const tunggu = (p, t, timeout = 20000) => terlihat(p.getByText(t, { exact: false })).first().waitFor({ timeout });
const judul = (p, nama) => terlihat(p.getByRole('heading', { name: nama, exact: true }));
const kotak = async l => (await terlihat(l).first().boundingBox()) ?? { x: -1, y: -1, width: 0, height: 0 };
const foto = (p, nama) => p.screenshot({ path: path.join(out, `${nama}.png`), fullPage: false });
async function tanpaLuapan(p, nama) {
  const peluap = await p.evaluate(() => [...document.querySelectorAll('body *')]
    .filter(el => el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().right > innerWidth + 1)
    .filter(el => { for (let a = el.parentElement; a; a = a.parentElement) if (['auto', 'scroll', 'hidden'].includes(getComputedStyle(a).overflowX)) return a.getBoundingClientRect().right > innerWidth + 1; return true; })
    .map(el => (el.innerText || el.tagName).slice(0, 30)));
  ok(peluap.length === 0, `${nama}: isi tanpa luapan horizontal${peluap.length ? ` (peluap: ${peluap.slice(0, 3).join(' | ')})` : ''}`);
}
/**
 * Teks yang keluar dari kotak induknya (mis. nominal panjang di strip yang
 * saling menimpa). Teks RN web dirender sebagai `[dir="auto"]`; yang
 * diperiksa teks tingkat atas, bukan rentang bersarang.
 */
async function teksMeluber(p, nama) {
  const luber = await p.evaluate(() => [...document.querySelectorAll('[dir="auto"]')]
    .filter(el => el.offsetParent !== null && !el.parentElement.closest('[dir="auto"]'))
    .filter(el => {
      const r = el.getBoundingClientRect(), pr = el.parentElement.getBoundingClientRect();
      return r.width > 0 && (r.left < pr.left - 1 || r.right > pr.right + 1 || el.scrollWidth > el.clientWidth + 1);
    })
    .map(el => (el.innerText || '').replace(/\s+/g, ' ').slice(0, 30)));
  ok(luber.length === 0, `${nama}: tidak ada teks yang meluber/menimpa${luber.length ? ` (${luber.slice(0, 3).join(' | ')})` : ''}`);
}
/** Kontrol terlihat (button/tab/radio/checkbox) yang lebih kecil dari 44 pt. */
async function targetKecil(p) {
  return p.evaluate(() => [...document.querySelectorAll('[role="button"],[role="tab"],[role="radio"],[role="checkbox"],button')]
    .filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden' && el.offsetParent !== null && r.bottom > 0 && r.top < innerHeight; })
    .map(el => ({ nama: (el.getAttribute('aria-label') || el.innerText || '').replace(/\s+/g, ' ').slice(0, 40), w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }))
    .filter(x => x.w < 44 || x.h < 44));
}

// ---- Data uji: tagihan bertransfer menunggu dan transaksi tabungan (API resmi, petugas) ----
const sid = nis => Number(sql(`SELECT id FROM santri WHERE nis='${nis}'`));
const sSmp = sid('V6S0001'), sTs = sid('V6S0002');
const PETUGAS = 'sbx_v6_bsa_b', ADMIN = 'sbx_admin', WALI = 'sbx_v6_wali_dua';

try {
  const t = await api(PETUGAS, '/v6/bsa/tagihan', { idempotency_key: kunci('t'), santri_id: sSmp, periode: '2025-12', jatuh_tempo: '2025-12-31', komponen: [{ nama: 'Syahriah', nominal: 150000 }] });
  const T = t.json?.data?.id;
  const st = await api(PETUGAS, `/v6/bsa/santri/${sSmp}/setoran`, { idempotency_key: kunci('s'), nominal: '80000', metode: 'Tunai' });
  const X = st.json?.data?.transaksi_id ?? st.json?.data?.id;
  const pm = await api(PETUGAS, `/v6/bsa/tagihan/${T}/pembayaran`, { idempotency_key: kunci('pm'), nominal: '10000', sumber: 'Transfer', tanggal_transaksi: '2025-12-20', referensi: 'RTV2F3-REF' });
  const PM = pm.json?.data?.id;
  const ps = await api(PETUGAS, `/v6/bsa/tagihan/${T}/pembayaran`, { idempotency_key: kunci('ps'), nominal: '20000', sumber: 'Tunai', tanggal_transaksi: '2025-12-20' });
  const PS = ps.json?.data?.id;
  await api(PETUGAS, `/v6/bsa/santri/${sSmp}/setoran`, { idempotency_key: kunci('st'), nominal: '15000', metode: 'Transfer', referensi: 'RTV2F3-SETOR' });
  ok([T, X, PM, PS].every(n => Number(n) > 0), `Data uji siap lewat API resmi (tagihan ${T}, transaksi ${X}, pembayaran menunggu ${PM}, sah ${PS})`);

  const RUTE = [
    ['/keuangan', 'Keuangan'],
    ['/keuangan/santri', 'Santri & saldo'],
    [`/keuangan/santri/${sSmp}`, 'Keuangan santri'],
    [`/keuangan/transaksi?santri_id=${sSmp}&mode=setoran`, 'Setoran tabungan'],
    [`/keuangan/transaksi?santri_id=${sSmp}&mode=keluar`, 'Penarikan atau belanja'],
    [`/keuangan/tagihan/${T}`, 'Rincian tagihan'],
    [`/keuangan/bayar?tagihan_id=${T}`, 'Catat pembayaran'],
    [`/keuangan/bayar-tabungan?tagihan_id=${T}`, 'Bayar dari tabungan'],
    [`/keuangan/ubah-tagihan?tagihan_id=${T}`, 'Revisi atau batal tagihan'],
    ['/keuangan/pembayaran', 'Pembayaran tagihan'],
    [`/keuangan/pembayaran/${PM}`, 'Kuitansi pembayaran'],
    ['/keuangan/tabungan', 'Mutasi tabungan'],
    [`/keuangan/tabungan/${X}`, 'Kuitansi tabungan'],
    ['/keuangan/verifikasi', 'Verifikasi transfer'],
    ['/keuangan/tunggakan', 'Tunggakan'],
    ['/keuangan/rekonsiliasi', 'Rekonsiliasi harian'],
    ['/keuangan/laporan', 'Laporan keuangan'],
    ['/keuangan/penerbitan', 'Terbitkan tagihan'],
    [`/keuangan/terbit-santri?santri_id=${sSmp}`, 'Tagihan satu santri'],
  ];

  console.log('\n=== A. K-7/K-10 dan aturan tampilan pada setiap layar (petugas, dibuka langsung) ===');
  const p = await masuk(PETUGAS);
  const kecil = new Map();
  for (const [rute, nama] of RUTE) {
    await p.goto(app + rute);
    await judul(p, nama).first().waitFor({ timeout: 25000 }).catch(() => {});
    await p.waitForTimeout(900);
    ok(await judul(p, nama).count() >= 1, `${rute}: judul kepala "${nama}"`);
    const t1 = await teks(p);
    ok(!(await terlihat(p.getByRole('button', { name: /Bagikan|Cetak|PDF/ })).count()), `${rute}: tanpa tombol Bagikan/Cetak (cetak tetap di website)`);
    ok(!/pendapatan|laba\b/i.test(t1), `${rute}: tanpa istilah "pendapatan"/"laba"`);
    ok(!/Rp\s?\d{4,}/.test(t1), `${rute}: rupiah selalu dengan pemisah titik`);
    await tanpaLuapan(p, rute);
    await teksMeluber(p, rute);
    for (const k of await targetKecil(p)) kecil.set(`${rute} · ${k.nama}`, `${Math.round(k.w)}×${Math.round(k.h)}`);
    await foto(p, `a-petugas${rute.replace(/[/?&=]+/g, '-')}`);
    const k = terlihat(p.getByRole('button', { name: 'Kembali', exact: true }));
    const b = await kotak(k);
    ok(await k.count() === 1 && b.width >= 44 && b.height >= 44, `${rute}: tepat satu ← terlihat, ${Math.round(b.width)}×${Math.round(b.height)} ≥ 44`);
    await k.first().click();
    await p.waitForURL(u => u.pathname === '/', { timeout: 15000 }).catch(() => {});
    ok(jalur(p) === '/', `${rute}: dibuka tanpa riwayat → ← membuka Beranda (kini ${jalur(p)})`);
  }

  console.log('\n=== B. ← dengan riwayat ===');
  await p.goto(app + '/keuangan');
  await tombol(p, 'Santri, saldo, dan tagihan').click();
  await judul(p, 'Santri & saldo').first().waitFor({ timeout: 20000 });
  await tombol(p, 'Kembali').click();
  await p.waitForTimeout(800);
  ok(jalur(p) === '/keuangan', `Menu → Santri → ← kembali ke menu Keuangan (kini ${jalur(p)})`);
  await p.goto(app + `/keuangan/santri/${sSmp}`);
  await tunggu(p, 'Riwayat tabungan');
  await terlihat(p.getByRole('button', { name: 'Buka tagihan Desember 2025' })).click();
  await judul(p, 'Rincian tagihan').first().waitFor({ timeout: 20000 });
  await tombol(p, 'Kembali').click();
  await p.waitForTimeout(800);
  ok(jalur(p) === `/keuangan/santri/${sSmp}`, `Rincian santri → tagihan → ← kembali ke rincian santri (kini ${jalur(p)})`);

  console.log('\n=== C. Isi menurut peran dan angka = API ===');
  await p.goto(app + '/keuangan');
  await tombol(p, 'Santri, saldo, dan tagihan').waitFor({ timeout: 20000 });
  const ubinPetugas = ['Santri, saldo, dan tagihan', 'Verifikasi transfer', 'Pembayaran tagihan', 'Mutasi tabungan', 'Tunggakan', 'Terbitkan tagihan bulanan', 'Rekonsiliasi harian', 'Laporan per periode'];
  const adaUbin = await Promise.all(ubinPetugas.map(async u => (await tombol(p, u).count()) === 1));
  ok(adaUbin.every(Boolean), `Menu petugas: 8 ubin (${ubinPetugas.filter((_, i) => !adaUbin[i]).join(', ') || 'lengkap'})`);
  ok(!/Rp\d/.test(await teks(p)), 'Menu petugas: tanpa angka (respons akses tidak memuat angka; tanpa angka karangan)');
  await foto(p, 'c-menu-petugas');

  await p.goto(app + `/keuangan/santri/${sSmp}`);
  await tunggu(p, 'Riwayat tabungan');
  const ds = (await api(PETUGAS, `/v6/bsa/santri/${sSmp}`)).json.data;
  const sisa = ds.tagihan.filter(x => x.status === 'Terbit').reduce((n, x) => n + Math.max(0, x.sisa), 0);
  let tx = await teks(p);
  // 375 px: strip berupa baris label–nilai (nominal jutaan tidak saling menimpa).
  ok(tx.includes(`Saldo tabungan ${rp(ds.saldo)}`) && tx.includes(`Sisa tagihan ${rp(sisa)}`) && tx.includes(`Transfer menunggu ${rp(ds.saldo_menunggu)}`),
    `Kepala profil petugas: Saldo ${rp(ds.saldo)} · Sisa tagihan ${rp(sisa)} · Transfer menunggu ${rp(ds.saldo_menunggu)} = API`);
  ok(ds.saldo_menunggu > 0 && tx.includes('RTV2F3-SETOR'), 'Petugas melihat setoran transfer menunggu beserta referensinya');
  const tabs = terlihat(p.getByRole('tab'));
  ok(await tabs.count() === 3, 'Tab segmen Ringkasan / Tagihan / Tabungan');
  await terlihat(p.getByRole('tab', { name: 'Tagihan' })).click();
  ok(await terlihat(p.getByText('Riwayat tabungan', { exact: false })).count() === 0 && await terlihat(p.getByText('Tagihan bulanan', { exact: false })).count() === 1, 'Tab Tagihan: hanya bagian tagihan');
  await terlihat(p.getByRole('tab', { name: 'Tabungan' })).click();
  ok(await terlihat(p.getByText('Tagihan bulanan', { exact: false })).count() === 0 && await terlihat(p.getByText('Riwayat tabungan', { exact: false })).count() === 1, 'Tab Tabungan: hanya bagian tabungan');
  await foto(p, 'c-rincian-santri-tab-tabungan');
  await terlihat(p.getByRole('tab', { name: 'Ringkasan' })).click();

  await p.goto(app + '/keuangan/tabungan');
  await tunggu(p, 'transaksi sah');
  const mut = (await api(PETUGAS, '/v6/bsa/tabungan')).json.data.rows;
  tx = await teks(p);
  ok(mut.length > 0 && mut.every(m => tx.includes(`${m.arah === 'Masuk' ? '+' : '−'}${rp(m.nominal)}`)), `Mutasi: ${mut.length} baris bernominal bertanda sesuai arah API`);

  await p.goto(app + `/keuangan/pembayaran/${PM}`);
  await tunggu(p, 'Bukti pembayaran');
  tx = await teks(p);
  const ikon = await p.evaluate(() => [...document.querySelectorAll('img,[style*="background-image"]')].map(e => e.getAttribute('src') || e.style.backgroundImage).join(' '));
  ok(tx.includes('Menunggu verifikasi') && !tx.includes('Pembayaran sah') && /clock/.test(ikon), 'Kuitansi transfer menunggu: panel "Menunggu verifikasi" berikon jam, bukan "Pembayaran sah"');
  await foto(p, 'c-kuitansi-menunggu');
  await p.goto(app + `/keuangan/pembayaran/${PS}`);
  await tunggu(p, 'Kuitansi (saat sah)');
  tx = await teks(p);
  ok(tx.includes('Pembayaran sah') && tx.includes('Sisa sesudah pembayaran'), 'Kuitansi sah: panel "Pembayaran sah" + kartu kuitansi bergaris putus');
  await foto(p, 'c-kuitansi-sah');

  await p.goto(app + '/keuangan/laporan');
  await tunggu(p, 'Saldo akhir');
  const lb = (await api(PETUGAS, `/v6/bsa/laporan?${new URLSearchParams(rentang('bulan_ini'))}`)).json.data;
  ok((await terlihat(p.getByText('Per bulan (grafik)', { exact: true })).count() > 0) === (lb.per_bulan.length >= 2), `Laporan bulan ini: grafik ${lb.per_bulan.length >= 2 ? 'tampil' : 'tidak tampil'} (seri ${lb.per_bulan.length} bulan)`);
  await terlihat(p.getByRole('radio', { name: 'Tahun ini' })).click();
  await tunggu(p, 'Saldo akhir');
  await p.waitForTimeout(800);
  const lt = (await api(PETUGAS, `/v6/bsa/laporan?${new URLSearchParams(rentang('tahun_ini'))}`)).json.data;
  const batang = await terlihat(p.getByRole('button', { name: /^[A-Z][a-z]{2} \d{4}: / })).count();
  ok(lt.per_bulan.length >= 2 ? batang === lt.per_bulan.length : batang === 0, `Laporan tahun ini: ${batang} batang = ${lt.per_bulan.length} bulan seri dari server`);
  tx = await teks(p);
  ok(tx.includes(`Penerimaan kas baru ${rp(lt.penerimaan_kas_baru)}`), `Laporan: kartu sorot "Penerimaan kas baru" ${rp(lt.penerimaan_kas_baru)} = API`);
  await foto(p, 'c-laporan-tahun-ini');
  for (const [k, v] of kecil) console.log(`   target < 44: ${k} (${v})`);
  ok(kecil.size === 0, `Target sentuh: seluruh kontrol terlihat pada 19 layar ≥ 44 pt (${kecil.size} di bawah)`);
  await p.context().close();

  const a = await masuk(ADMIN);
  await a.goto(app + '/keuangan');
  await tunggu(a, 'Admin — hanya membaca');
  const ubinAdmin = ['Santri, saldo, dan tagihan', 'Transfer menunggu verifikasi', 'Pembayaran tagihan', 'Mutasi tabungan', 'Tunggakan', 'Rekonsiliasi harian', 'Laporan per periode'];
  const adaAdmin = await Promise.all(ubinAdmin.map(async u => (await tombol(a, u).count()) === 1));
  ok(adaAdmin.every(Boolean) && !(await teks(a)).includes('Terbitkan tagihan bulanan'), 'Menu admin: 7 ubin hanya baca, tanpa penerbitan');
  await foto(a, 'c-menu-admin');
  await a.goto(app + `/keuangan/santri/${sSmp}`);
  await tunggu(a, 'Riwayat tabungan');
  ok(!(await tombol(a, 'Setoran').count()) && !(await tombol(a, 'Penarikan/belanja').count()) && !(await tombol(a, 'Terbitkan tagihan').count()) && !(await tombol(a, 'Sudah diperiksa — verifikasi').count()),
    'Rincian santri admin: tanpa tombol mutasi (server tetap memutuskan)');
  await a.goto(app + `/keuangan/tagihan/${T}`);
  await tunggu(a, 'Rincian biaya');
  ok(!(await tombol(a, 'Catat pembayaran tunai/transfer').count()) && !(await tombol(a, 'Tindakan lain').count()), 'Rincian tagihan admin: tanpa bilah tindakan dan tanpa ⋯');
  await a.context().close();

  const w = await masuk(WALI);
  await w.goto(app + '/keuangan');
  await tunggu(w, 'Riwayat dan laporan');
  const anak = (await api(WALI, '/v6/bsa/santri')).json.data.rows;
  tx = await teks(w);
  ok(anak.length === 2 && anak.every(s => tx.includes(`Saldo tabungan ${rp(s.saldo)} ${s.nama}`) && tx.includes(`${rp(s.sisa_tagihan)} Sisa tagihan (${s.tagihan_terbuka} terbuka)`)),
    `Menu orang tua: kartu sorot saldo + sisa tagihan per anak = API (${anak.map(s => `${rp(s.saldo)}/${rp(s.sisa_tagihan)}`).join(', ')})`);
  await foto(w, 'c-menu-wali');
  await terlihat(w.getByRole('button', { name: 'Buka keuangan SBX V6 Santri SMP' })).click();
  await tunggu(w, 'Riwayat tabungan');
  tx = await teks(w);
  ok(!tx.includes('Transfer menunggu') && !tx.includes('RTV2F3-SETOR') && tx.includes('Saldo tabungan') && tx.includes('Sisa tagihan'), 'Rincian anak (orang tua): strip Saldo · Sisa tagihan tanpa transfer menunggu/referensi');
  await foto(w, 'c-rincian-anak-wali');
  await w.context().close();

  console.log('\n=== E. 768/1280 px dan mode gelap ===');
  const LAYAR = [['/keuangan', 'Keuangan'], [`/keuangan/santri/${sSmp}`, 'Keuangan santri'], [`/keuangan/tagihan/${T}`, 'Rincian tagihan'],
    [`/keuangan/transaksi?santri_id=${sSmp}&mode=setoran`, 'Setoran tabungan'], [`/keuangan/pembayaran/${PS}`, 'Kuitansi pembayaran'], ['/keuangan/laporan', 'Laporan keuangan'],
    ['/keuangan/rekonsiliasi', 'Rekonsiliasi harian'], ['/keuangan/verifikasi', 'Verifikasi transfer']];
  for (const [lebar, skema] of [[375, 'dark'], [768, 'light'], [768, 'dark'], [1280, 'light'], [1280, 'dark']]) {
    const q = await masuk(PETUGAS, { lebar, tinggi: lebar === 375 ? 812 : 900, skema });
    for (const [rute, nama] of LAYAR) {
      await q.goto(app + rute);
      await judul(q, nama).first().waitFor({ timeout: 25000 }).catch(() => {});
      await q.waitForTimeout(700);
      await tanpaLuapan(q, `${lebar} ${skema} ${rute}`);
      await teksMeluber(q, `${lebar} ${skema} ${rute}`);
      if (rute === `/keuangan/santri/${sSmp}` || rute === `/keuangan/tagihan/${T}` || rute === '/keuangan') await foto(q, `e-${lebar}-${skema}${rute.replace(/[/?&=]+/g, '-')}`);
    }
    await q.context().close();
  }
  for (const skema of ['light', 'dark']) {
    const q = await masuk(WALI, { lebar: 768, tinggi: 900, skema });
    await q.goto(app + '/keuangan');
    await tunggu(q, 'Riwayat dan laporan');
    await tanpaLuapan(q, `wali 768 ${skema} /keuangan`);
    await foto(q, `e-wali-768-${skema}-menu`);
    await q.context().close();
  }
} catch (e) {
  ok(false, 'Uji berhenti: ' + (e?.stack || e));
} finally {
  await browser.close();
}
ok(luar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${[...new Set(luar)].join(', ') || 'tidak ada'})`);
ok(galatHalaman.length === 0, `Tanpa galat halaman (${galatHalaman.slice(0, 2).join(' | ') || 'tidak ada'})`);
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);

function rentang(pilihan) {
  const n = new Date();
  const per = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  if (pilihan === 'tahun_ini') return { dari: `${n.getFullYear()}-01`, sampai: per(n) };
  return { dari: per(n), sampai: per(n) };
}
