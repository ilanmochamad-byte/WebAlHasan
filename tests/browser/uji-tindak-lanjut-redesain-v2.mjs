// Probe tindak lanjut audit redesain tampilan aplikasi gelombang 2 (13 November 2026).
// Satu pemeriksaan perilaku per butir TL yang dapat diuji di Expo web. Dijalankan
// pada baseline (`origin/main` aplikasi 1.8.0) dan kode akhir: butir yang diperbaiki
// harus GAGAL di baseline dan LULUS di akhir (kendali mutu §7 rencana).
//
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:<port> DB_NAME=<test_*_test> \
//     node uji-tindak-lanjut-redesain-v2.mjs <akun|izin|keuangan|pembinaan|kesehatan>
//
// Hanya 127.0.0.1 dan DB test_*_test; data sintetis fixture.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya 127.0.0.1 dan DB test_*_test');
const bagian = process.argv[2];
const PASS = 'Sandbox#123';
let lulus = 0, gagal = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const luar = [];

async function token(user) {
  const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-tl' }) });
  return (await r.json()).data?.token;
}
async function api(user, p, body) {
  const t = await token(user);
  const r = await fetch(`${web}/api/v1${p}`, { method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${t}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const kunci = n => `tl-${n}-${Date.now()}-${Math.random().toString(36).slice(2)}`;

const browser = await chromium.launch({ headless: true });
async function masuk(user, { lebar = 375 } = {}) {
  const ctx = await browser.newContext({ viewport: { width: lebar, height: 812 }, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  page.on('dialog', d => d.dismiss());
  await page.route('**/*', async route => {
    const req = route.request(); const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type,idempotency-key', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    try { const response = await route.fetch(); await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } }); } catch { /* konteks ditutup */ }
  });
  await page.goto(app + '/login');
  if (user) {
    await page.getByPlaceholder('Masukkan username').fill(user);
    await page.getByPlaceholder('Masukkan password').fill(PASS);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(u => u.pathname !== '/login', { timeout: 30000 });
    await page.waitForTimeout(1200);
  }
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const tombol = (p, n) => p.getByRole('button', { name: n, exact: true });
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout }).then(() => true, () => false);
const terlihat = async l => { let n = 0; for (const e of await l.all()) if (await e.isVisible()) n++; return n; };
/** Butir di ⋯: buka menu "Tindakan lain", periksa butir, tutup lagi. */
async function diMenu(p, label) {
  if (await tombol(p, 'Tindakan lain').count() === 0) return false;
  // Butir harus MUNCUL karena menu dibuka (bukan tombol yang sudah tampil di layar).
  const sebelum = await terlihat(tombol(p, label));
  await tombol(p, 'Tindakan lain').click();
  const ada = await tunggu(p, 'Tindakan lain', 3000) && await terlihat(tombol(p, label)) > sebelum;
  await p.keyboard.press('Escape').catch(() => {});
  return ada;
}

try {
  if (bagian === 'akun') {
    const l = await masuk(null);
    await tunggu(l, 'Masukkan username');
    const tl = await teks(l);
    ok(/Assalamu.alaikum/.test(tl) && tl.includes('Masuk ke akun pesantren Anda') && !/Assalamu.alaikum,/.test(tl), 'TL-01: layar Masuk menyapa "Assalamu\'alaikum" (tanpa koma Beranda) + ajakan masuk');
    await l.context().close();

    const p = await masuk('sbx_pengurus_a');
    await p.goto(app + '/profil'); await tunggu(p, 'Perangkat & push'); await tunggu(p, 'Rincian penugasan aktif');
    const tp = await teks(p);
    ok(tp.includes('Pemberitahuan') && !tp.includes('Akun & pemberitahuan'), 'TL-03: kelompok Akun dan Pemberitahuan terpisah');
    ok(!tp.includes('Tempat, tanggal lahir') && await terlihat(p.getByRole('button', { name: /^Data diri/ })) === 1, 'TL-03: Data diri berupa baris ›, nilai tidak tampil langsung di Profil');
    if (await p.getByRole('button', { name: /^Data diri/ }).count()) {
      await p.getByRole('button', { name: /^Data diri/ }).click();
      ok(await tunggu(p, 'Tempat, tanggal lahir') && new URL(p.url()).pathname === '/akun/data-diri', 'TL-03: Data diri › membuka rincian akun/data-diri');
    } else ok(false, 'TL-03: Data diri › membuka rincian akun/data-diri');
    await p.goto(app + '/akun/edit-profil'); await tunggu(p, 'Diatur admin');
    ok(await terlihat(p.getByRole('button', { name: /^(Pilih|Ganti) foto$/ })) === 0, 'TL-02: tombol pilih/ganti foto tidak langsung di halaman');
    if (await tombol(p, 'Ubah foto').count()) {
      await tombol(p, 'Ubah foto').click();
      ok(await tunggu(p, 'Foto profil', 5000) && await terlihat(p.getByRole('button', { name: /^(Pilih|Ganti) foto$/ })) === 1 && await terlihat(tombol(p, 'Batal')) === 1, 'TL-02: "Ubah foto" membuka lembar bawah Foto profil (Pilih/Ganti foto, Batal)');
      await tombol(p, 'Batal').click();
    } else ok(false, 'TL-02: "Ubah foto" membuka lembar bawah Foto profil (Pilih/Ganti foto, Batal)');
    await p.goto(app + '/notifikasi/perangkat'); await tunggu(p, 'Seluruh perangkat akun ini');
    ok(await p.getByRole('switch', { name: 'Push di perangkat ini' }).count() === 1 && await terlihat(tombol(p, 'Nyalakan push')) === 0, 'TL-09: sakelar "Push di perangkat ini" menggantikan tombol Nyalakan/Matikan');
    await p.context().close();
  }

  if (bagian === 'izin') {
    const santri = Number(sql("SELECT id FROM santri WHERE nama_santri='SBX Santri A1' LIMIT 1"));
    const tgl = (o) => { const d = new Date(Date.now() + (400 + o) * 86400e3); return d.toISOString().slice(0, 10); };
    const v2 = await api('sbx_pengurus_a', '/izin/pengajuan?mode=pengurus', { santri_id: santri, tgl_izin: tgl(0), tgl_kembali: tgl(1), alasan: 'Probe tindak lanjut', idempotency_key: kunci('v2') });
    const id = v2.json?.data?.id;
    ok(v2.status === 201 && id > 0, `fixture: pengajuan V2 dibuat lewat API (${v2.status})`);
    const pg = await masuk('sbx_pengurus_a');
    await pg.goto(app + `/izin/${id}?mode=pengurus&baru=1`);
    const sukses = await tunggu(pg, 'Lihat pengajuan', 15000);
    ok(sukses && (await teks(pg)).includes('Pengajuan terkirim') && await terlihat(pg.getByText('Riwayat perubahan')) === 0, 'TL-07: pengajuan baru → layar sukses "Pengajuan terkirim" + "Lihat pengajuan"');
    if (sukses) { await tombol(pg, 'Lihat pengajuan').click(); ok(await tunggu(pg, 'Riwayat perubahan'), 'TL-07: "Lihat pengajuan" membuka rincian'); } else ok(false, 'TL-07: "Lihat pengajuan" membuka rincian');
    await pg.context().close();

    const ad = await masuk('sbx_admin');
    await ad.goto(app + `/izin/${id}?mode=admin`); await tunggu(ad, 'Riwayat perubahan');
    ok(await terlihat(tombol(ad, 'Tetapkan murobi')) === 0 && await terlihat(ad.getByText('Penetapan / perbaikan routing murobi')) === 0, 'TL-08: admin pemutus — Tetapkan murobi tidak di badan layar');
    ok(await diMenu(ad, 'Tetapkan murobi'), 'TL-08: Tetapkan murobi tersedia di ⋯');
    await ad.context().close();

    const anak = Number(sql("SELECT id FROM santri WHERE nis='V7S0001' LIMIT 1"));
    const v7 = await api('sbx_v7_wali_a', '/v7/izin/wali/pengajuan', { santri_id: anak, tgl_izin: tgl(10), tgl_kembali: tgl(11), alasan: 'Probe tindak lanjut wali', catatan: '', idempotency_key: kunci('v7') });
    const idw = v7.json?.data?.id;
    ok(v7.status === 201 && idw > 0, `fixture: pengajuan wali dibuat lewat API (${v7.status})`);
    const w = await masuk('sbx_v7_wali_a');
    await w.goto(app + `/izin-wali/${idw}?baru=1`);
    ok(await tunggu(w, 'Lihat pengajuan', 15000) && (await teks(w)).includes('Pengajuan terkirim'), 'TL-07: izin wali baru → layar sukses + "Lihat pengajuan"');
    await w.goto(app + `/izin-wali/${idw}`); await tunggu(w, 'Rentang izin');
    ok(await terlihat(w.getByRole('button', { name: 'Muat ulang', exact: true })) === 1 && (await w.getByRole('button', { name: 'Muat ulang', exact: true }).boundingBox())?.y < 120, 'TL-08: "Muat ulang" rincian izin wali di kepala layar');
    // TL-10: pembesaran teks peramban (FAB melebar tanpa fontScale berubah).
    await w.goto(app + '/izin-wali'); await tunggu(w, 'Ajukan izin');
    await w.addStyleTag({ content: '[aria-label="Ajukan izin baru"] div[dir="auto"] { font-size: 34px !important; line-height: 42px !important; }' });
    await w.waitForTimeout(800);
    const fab = await w.getByRole('button', { name: 'Ajukan izin baru' }).first().boundingBox();
    ok(fab !== null && fab.width <= 375 / 2 + 1 || (fab !== null && fab.width > 300), `TL-10: teks FAB diperbesar — FAB ≤ setengah layar atau beralih ke tombol lebar penuh (lebar ${Math.round(fab?.width ?? 0)} px)`);
    await w.context().close();
  }

  if (bagian === 'keuangan') {
    const sSmp = Number(sql("SELECT id FROM santri WHERE nis='V6S0001'"));
    const t = await api('sbx_v6_bsa_b', '/v6/bsa/tagihan', { idempotency_key: kunci('t'), santri_id: sSmp, periode: '2025-01', jatuh_tempo: '2025-01-31', komponen: [{ nama: 'Probe TL', nominal: 50000 }] });
    const tid = t.json?.data?.id;
    ok(t.status === 201 && tid > 0, `fixture: tagihan dibuat lewat API (${t.status})`);
    const p = await masuk('sbx_v6_bsa_b');
    await p.goto(app + `/keuangan/tagihan/${tid}`); await tunggu(p, 'Rincian biaya');
    ok(await terlihat(tombol(p, 'Catat pembayaran tunai/transfer')) === 1 && await terlihat(tombol(p, 'Bayar dari tabungan')) === 0, 'TL-11: bilah rincian tagihan hanya "Catat pembayaran tunai/transfer"');
    ok(await diMenu(p, 'Bayar dari tabungan'), 'TL-11: "Bayar dari tabungan" di ⋯');
    await p.goto(app + `/keuangan/terbit-santri?santri_id=${sSmp}`); await tunggu(p, 'Tampilkan tagihan');
    await tombol(p, 'Tampilkan tagihan').click(); await tunggu(p, 'Pratinjau: belum ada tagihan'); await tunggu(p, 'Tagihan ');
    const tt = await teks(p);
    ok(['Akan terbit', 'Sudah ada', 'Dilewati', 'Total terbit'].every(x => tt.includes(x)), 'TL-13: pratinjau terbit satu santri berupa StatGrid Akan terbit / Sudah ada / Dilewati / Total terbit');
    await p.context().close();
    const wali = sql("SELECT u.username FROM users u WHERE u.username LIKE 'sbx_v6_wali%' ORDER BY u.id LIMIT 1");
    const w = await masuk(wali);
    await w.goto(app + '/keuangan'); await tunggu(w, 'Saldo tabungan');
    const pintas = w.getByRole('button', { name: 'Mutasi tabungan', exact: true });
    ok(await terlihat(pintas) >= 1 && await terlihat(w.getByRole('button', { name: 'Tagihan', exact: true })) >= 1, 'TL-12: kartu anak memuat pintasan Tagihan dan Mutasi tabungan');
    if (await pintas.count()) {
      await pintas.first().click(); await tunggu(w, 'Ringkasan');
      const u = new URL(w.url());
      ok(u.pathname.startsWith('/keuangan/santri/') && u.searchParams.get('bagian') === 'tabungan' && await w.getByRole('tab', { name: 'Tabungan' }).getAttribute('aria-selected') === 'true', 'TL-12: pintasan membuka rincian anak pada tab Tabungan');
    } else ok(false, 'TL-12: pintasan membuka rincian anak pada tab Tabungan');
    await w.context().close();
  }

  if (bagian === 'pembinaan') {
    const kasus = sql("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Internal' ORDER BY id LIMIT 1");
    const p = await masuk('sbx_pengurus_a');
    await p.goto(app + `/pembinaan/detail?jenis=kasus&id=${kasus}`); await tunggu(p, 'Muat ulang versi terbaru');
    const tab = n => p.getByRole('tab', { name: n });
    ok(await tab('Ringkasan').count() === 1 && await tab(/^Sesi/).count() === 1 && await tab('Riwayat').count() === 1, 'TL-14: rincian kasus bertab Ringkasan / Sesi / Riwayat');
    if (await tab(/^Sesi/).count()) {
      ok(await terlihat(p.getByText(/^Sesi #\d+/)) === 0 || await tab('Ringkasan').getAttribute('aria-selected') === 'true', 'TL-14: tab Ringkasan bawaan');
      await tab(/^Sesi/).click();
      ok(await tab(/^Sesi/).getAttribute('aria-selected') === 'true', 'TL-14: tab Sesi dapat dipilih');
    }
    await p.context().close();
  }

  if (bagian === 'kesehatan') {
    const t = await token('sbx_v5_petugas_f');
    const santriId = Number(sql("SELECT id FROM santri WHERE nis='V5S0201'"));
    const kini = new Date(Date.now() + 7 * 3600e3 - 3600e3).toISOString().slice(0, 16);
    const r = await fetch(web + '/api/v1/v5/kesehatan/kunjungan', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + t }, body: JSON.stringify({
      santri_id: santriId, status: 'Tercatat', waktu_kunjungan: kini, keluhan: 'TL probe keluhan', catatan_klinis: 'TL probe catatan', tindakan: 'TL probe tindakan',
      obat: [], rujukan: [], tindak_lanjut: [], idempotency_key: kunci('k') }) });
    ok(r.status === 201, `fixture: kunjungan dicatat lewat API (${r.status})`);
    const kid = sql("SELECT id FROM kesehatan_kunjungan WHERE keluhan='TL probe keluhan' ORDER BY id DESC LIMIT 1");
    const p = await masuk('sbx_admin');
    await p.goto(app + `/kesehatan/kunjungan/${kid}`); await tunggu(p, 'Catatan klinik');
    ok(await terlihat(p.getByRole('button', { name: /^Riwayat versi/ })) === 0 && await terlihat(tombol(p, 'Riwayat akses')) === 0, 'TL-16: riwayat versi/akses tidak lagi baris di badan layar');
    ok(await diMenu(p, 'Riwayat akses'), 'TL-16: riwayat akses di ⋯');
    const posisi = await p.evaluate(() => {
      const cari = re => [...document.querySelectorAll('div,span')].find(e => re.test(e.textContent || '') && e.children.length === 0)?.getBoundingClientRect().top ?? -1;
      return { privat: cari(/^Privat: hanya petugas kesehatan/), waktu: cari(/^Waktu kunjungan$/) };
    });
    ok(posisi.privat >= 0 && posisi.waktu >= 0 && posisi.privat < posisi.waktu && posisi.privat < 812, `TL-31: penanda privat tampil di layar pertama, di atas rincian (y=${Math.round(posisi.privat)} vs ${Math.round(posisi.waktu)})`);
    await p.goto(app + `/kesehatan/akses?id=${kid}`); await tunggu(p, 'pembukaan');
    ok(await p.evaluate(() => document.querySelectorAll('[data-testid], div').length > 0) && (await teks(p)).includes('kanal'), 'smoke: halaman riwayat akses termuat (bukan bukti perbaikan)');
    await p.context().close();
  }
} catch (e) {
  ok(false, `Pengecualian: ${e.message.split('\n')[0]}`);
} finally {
  await browser.close();
}
ok(luar.length === 0, `tanpa permintaan keluar 127.0.0.1 (${[...new Set(luar)].join(', ')})`);
console.log(`\nRingkasan (${bagian}): ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
