import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi PRD V7 Fase 4 — izin orang tua melalui pembimbing (Expo WEB dari
// repo alhasanApps + API/DB uji nyata). Ini Expo WEB (Chromium), bukan perangkat native.
//
//   V7F4_DB=test_v7_f4_app_test bash tests/v7_phase4_db.sh
//   DB_NAME=test_v7_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8976 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8976/api/v1 npx expo start --web --port 8098 --clear &)
//   (cd tests/browser && DB_NAME=test_v7_f4_app_test V7_WEB=http://127.0.0.1:8976 V7_APP=http://127.0.0.1:8098 OUT_DIR=… node uji-v7-fase4-app.mjs)
//
//   P-A  wali A: pilih anak, validasi, ketukan ganda + jawaban hilang → satu POST
//        dan kirim ulang berkunci sama → satu ID; benturan tanggal 409
//   P-B  wali kedua ringkas tanpa tombol; wali asing/pembimbing lain/murobi
//        sebelum penerusan ditolak tanpa isi
//   P-C  pembimbing: minta perbaikan; layar basi di perangkat lain → 409 →
//        "Muat versi terbaru"
//   P-D  wali A memperbaiki (isi sama ditolak), pembimbing meneruskan (ketukan ganda)
//   P-E  murobi: notifikasi → rincian V7 → Perizinan V2 → setujui (ketukan ganda)
//   P-F  paritas status aplikasi/API/website; wali kedua ringkas
//   P-G  admin menetapkan pembimbing; wali membatalkan dengan konfirmasi
//   P-H  pencabutan saat layar terbuka; offline; sesi berakhir; logout → masuk
//   P-I  penyimpanan/konsol tanpa alasan/token; nol permintaan keluar; 375 px
const web = process.env.V7_WEB, app = process.env.V7_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/v7f4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const PENANDA = 'RAHASIA-APP-V7F4';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v7-f4-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, {
    method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const kunci = p => `app-uji-${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const sSatu = Number(sql("SELECT id FROM santri WHERE nis='V7S0001'"));
const sDua = Number(sql("SELECT id FROM santri WHERE nis='V7S0003'"));
const sCadangan = Number(sql("SELECT id FROM santri WHERE nis='V7S0008'"));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postV7 = [];
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
    if (route.request().method() === 'POST' && (u.pathname.startsWith('/api/v1/v7/') || /\/izin\/pengajuan\/\d+\/keputusan$/.test(u.pathname))) {
      postV7.push({ user, path: u.pathname, body: route.request().postDataJSON() });
    }
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
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-v7-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
const statusDb = id => sql(`SELECT status FROM izin_pengajuan WHERE id=${id}`);
const bukaPintu = async p => {
  await p.goto(app + '/perizinan');
  await p.getByRole('button', { name: 'Buka izin orang tua melalui pembimbing' }).click();
};
const halamanSaya = [];

try {
  console.log('\n=== P-A. Wali A mengajukan dari aplikasi ===');
  ok(Number(sql("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali'")) === 0, 'DB uji bersih (belum ada pengajuan wali)');
  let buang = 1;
  const w = await halamanApp('sbx_v7_wali_a', { buangJawaban: (u, r) => r.method() === 'POST' && u.pathname.endsWith('/v7/izin/wali/pengajuan') && buang-- > 0 });
  halamanSaya.push(w);
  await bukaPintu(w);
  await tunggu(w, 'Izin anak melalui pembimbing');
  await lebar(w, 'Pintu izin wali');
  await foto(w, 'wali-pintu');
  await tombol(w, 'Ajukan izin').click();
  await tunggu(w, 'Rentang izin');
  await tombol(w, 'Tinjau pengajuan').click();
  await tunggu(w, 'Pilih anak terlebih dahulu.');
  ok(true, 'Validasi lokal: anak wajib dipilih (pesan Indonesia)');
  await w.getByRole('radio', { name: 'SBX V7 Santri Satu (V7S0001)' }).click();
  await w.getByLabel('Tanggal izin').fill('2027-11-02');
  await w.getByLabel('Tanggal kembali').fill('2027-11-03');
  await w.getByLabel('Alasan izin').fill(`${PENANDA} acara keluarga`);
  await w.getByLabel('Catatan', { exact: true }).fill('Dijemput ayah');
  await tombol(w, 'Tinjau pengajuan').click();
  await tunggu(w, 'Tinjau sebelum mengirim');
  await lebar(w, 'Formulir ajukan izin');
  await foto(w, 'wali-buat');
  const sebelum = postV7.length;
  await tombol(w, 'Kirim pengajuan').dblclick();
  await tunggu(w, 'offline atau server tidak terjangkau');
  ok(postV7.length - sebelum === 1, `Ketukan ganda "Kirim pengajuan" → satu POST (terhitung ${postV7.length - sebelum}); jawaban hilang tampil sebagai pesan jaringan`);
  ok(Number(sql(`SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali' AND santri_id=${sSatu}`)) === 1, 'Server sudah menyimpan pengajuan pertama walau jawaban hilang');
  await tombol(w, 'Kirim pengajuan').click();
  await w.waitForURL(/\/izin-wali\/\d+/);
  await tunggu(w, 'sudah terkirim sebelumnya');
  const dua = postV7.slice(-2);
  ok(dua.length === 2 && dua[0].body.idempotency_key === dua[1].body.idempotency_key, 'Kirim ulang memakai kunci idempotensi yang SAMA');
  const id = Number(new URL(w.url()).pathname.split('/').pop());
  ok(Number(sql(`SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali' AND santri_id=${sSatu}`)) === 1 && statusDb(id) === 'Menunggu Pembimbing', `Tepat satu pengajuan #${id} berstatus Menunggu Pembimbing`);
  await tunggu(w, 'Menunggu tinjauan pembimbing');
  ok((await teks(w)).includes(`${PENANDA} acara keluarga`) && (await tombol(w, 'Batalkan pengajuan').count()) === 1, 'Rincian wali pengaju: isi dan tombol batal tampil');
  await lebar(w, 'Rincian wali pengaju');
  await foto(w, 'wali-detail');
  // Benturan tanggal: pengajuan kedua bertumpang tindih → 409 dari server, pesan jelas.
  await w.goto(app + '/izin-wali/buat');
  await w.getByRole('radio', { name: 'SBX V7 Santri Satu (V7S0001)' }).click();
  await w.getByLabel('Tanggal izin').fill('2027-11-03');
  await w.getByLabel('Tanggal kembali').fill('2027-11-03');
  await w.getByLabel('Alasan izin').fill(`${PENANDA} bentrok`);
  await tombol(w, 'Tinjau pengajuan').click();
  await tombol(w, 'Kirim pengajuan').click();
  await tunggu(w, 'bersinggungan');
  ok(Number(sql(`SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali' AND santri_id=${sSatu}`)) === 1, 'Rentang bertumpang tindih → pesan server (409), tanpa baris baru');

  console.log('\n=== P-B. Wali kedua, wali asing, di luar cakupan ===');
  const wb = await halamanApp('sbx_v7_wali_b');
  await wb.goto(app + `/izin-wali/${id}`);
  await tunggu(wb, 'Diajukan wali lain');
  const tb = await teks(wb);
  ok(!tb.includes(PENANDA) && !tb.includes('Dijemput ayah') && (await tombol(wb, 'Batalkan pengajuan').count()) === 0 && (await tombol(wb, 'Perbaiki dan kirim ulang').count()) === 0,
    'Wali kedua: status ringkas tanpa alasan, catatan, atau tombol mutasi');
  ok(tb.includes('Menunggu tinjauan pembimbing'), 'Wali kedua melihat status yang sama');
  await foto(wb, 'wali-kedua');
  for (const [u, label] of [['sbx_ortu_b', 'Wali asing'], ['sbx_v7_pembimbing_dua', 'Pembimbing lain'], ['sbx_murobi_a', 'Murobi sebelum penerusan']]) {
    const p = await halamanApp(u);
    await p.goto(app + `/izin-wali/${id}`);
    await tunggu(p, 'Data belum dapat ditampilkan');
    const tp = await teks(p);
    ok(!tp.includes(PENANDA) && !tp.includes('SBX V7 Santri Satu'), `${label}: rincian ditolak server, tanpa nama santri atau alasan`);
    await p.context().close();
  }

  console.log('\n=== P-C. Pembimbing meminta perbaikan; layar basi → 409 ===');
  const pb = await halamanApp('sbx_v7_pembimbing_satu');
  await bukaPintu(pb);
  await pb.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Satu nomor ${id}` }).click();
  await tunggu(pb, 'Tinjauan pembimbing');
  await lebar(pb, 'Rincian pembimbing');
  const pbBasi = await halamanApp('sbx_v7_pembimbing_satu');
  await pbBasi.goto(app + `/izin-wali/${id}`);
  await tunggu(pbBasi, 'Teruskan ke murobi');
  await pb.getByRole('radio', { name: 'Minta perbaikan' }).click();
  await tombol(pb, 'Minta perbaikan').click();
  await tunggu(pb, 'Catatan perbaikan untuk wali wajib');
  await pb.getByLabel('Catatan perbaikan untuk wali').fill('Mohon perjelas jam penjemputan');
  await tombol(pb, 'Minta perbaikan').click();
  await tunggu(pb, 'Permintaan perbaikan terkirim');
  ok(statusDb(id) === 'Perlu Perbaikan Wali', 'Minta perbaikan dari aplikasi → status Perlu Perbaikan Wali');
  await foto(pb, 'pembimbing-perbaikan');
  await tombol(pbBasi, 'Teruskan ke murobi').click();
  await tunggu(pbBasi, 'Muat ulang data sebelum mencoba kembali');
  ok((await tombol(pbBasi, 'Muat versi terbaru').count()) === 1 && statusDb(id) === 'Perlu Perbaikan Wali', 'Perangkat kedua (layar basi) → 409, status tidak berubah, tombol "Muat versi terbaru"');
  await tombol(pbBasi, 'Muat versi terbaru').click();
  await tunggu(pbBasi, 'Perlu perbaikan wali');
  ok((await tombol(pbBasi, 'Teruskan ke murobi').count()) === 0, 'Sesudah muat ulang, tindakan tinjauan tidak lagi ditawarkan');
  await pbBasi.context().close();

  console.log('\n=== P-D. Wali memperbaiki; pembimbing meneruskan ===');
  await w.goto(app + `/izin-wali/${id}`);
  await tunggu(w, 'Mohon perjelas jam penjemputan');
  await tombol(w, 'Perbaiki dan kirim ulang').click();
  await tunggu(w, 'Permintaan pembimbing');
  await tombol(w, 'Kirim perbaikan').click();
  await tunggu(w, 'Isi pengajuan belum diubah');
  ok(true, 'Perbaikan tanpa perubahan ditolak sebelum dikirim');
  await w.getByLabel('Alasan izin').fill(`${PENANDA} acara keluarga, dijemput 15.00`);
  await lebar(w, 'Formulir perbaikan');
  await tombol(w, 'Kirim perbaikan').click();
  await w.waitForURL(u => u.pathname === `/izin-wali/${id}`);
  await tunggu(w, 'Menunggu tinjauan pembimbing');
  ok(statusDb(id) === 'Menunggu Pembimbing' && Number(sql(`SELECT COUNT(*) FROM izin_wali_perbaikan WHERE pengajuan_id=${id}`)) === 1, 'Perbaikan terkirim → kembali ke pembimbing, satu versi perbaikan tercatat');
  await pb.goto(app + `/izin-wali/${id}`);
  await tunggu(pb, 'Teruskan ke murobi');
  ok((await teks(pb)).includes('Versi 2 → 3'), 'Pembimbing melihat versi isi sebelum/sesudah');
  const sebelumF = postV7.length;
  await tombol(pb, 'Teruskan ke murobi').dblclick();
  await tunggu(pb, 'Pengajuan diteruskan');
  ok(postV7.length - sebelumF === 1 && statusDb(id) === 'Diajukan', 'Ketukan ganda "Teruskan" → satu POST, status Diajukan (murobi tunggal)');

  console.log('\n=== P-E. Murobi: notifikasi → rincian → keputusan V2 ===');
  const notif = (await api('sbx_murobi_a', '/notifikasi?per_page=100')).json.data.items.find(n => n.tautan?.tipe === 'izin_wali' && n.tautan.pengajuan_id === id);
  ok(Boolean(notif) && notif.santri_nama === null && !JSON.stringify(notif).includes(PENANDA), 'Murobi menerima notifikasi izin_wali tanpa nama/alasan');
  const m = await halamanApp('sbx_murobi_a');
  await m.goto(app + `/notifikasi/${notif.id}`);
  await tombol(m, 'Buka pengajuan izin').click();
  await m.waitForURL(u => u.pathname === `/izin-wali/${id}`);
  await tunggu(m, 'Putuskan di Perizinan');
  await foto(m, 'murobi-v7');
  await tombol(m, 'Putuskan di Perizinan').click();
  await m.waitForURL(u => u.pathname === `/izin/${id}`);
  await tunggu(m, 'Diajukan orang tua');
  await lebar(m, 'Rincian V2 murobi (asal wali)');
  ok((await tombol(m, 'Batalkan pengajuan').count()) === 0, 'Layar V2 murobi tidak menawarkan pembatalan pengajuan wali');
  await m.getByPlaceholder('Minimal 3 karakter').first().fill('Sesuai jadwal pulang');
  const sebelumK = postV7.length;
  await tombol(m, 'Setujui').dblclick();
  await tunggu(m, 'Keputusan Disetujui tersimpan');
  ok(postV7.length - sebelumK === 1 && Number(sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${id}`)) === 1, 'Ketukan ganda "Setujui" → satu POST, satu keputusan efektif');
  await foto(m, 'murobi-v2');
  await m.context().close();

  console.log('\n=== P-F. Paritas aplikasi / API / website ===');
  await w.goto(app + `/izin-wali/${id}`);
  await tunggu(w, 'Keputusan akhir');
  const tw = await teks(w);
  ok(tw.includes('Disetujui murobi') && tw.includes('Disetujui · Murobi') && (await tombol(w, 'Batalkan pengajuan').count()) === 0, 'Aplikasi wali: Disetujui murobi, keputusan tampil, batal tidak ditawarkan');
  const apiW = (await api('sbx_v7_wali_a', `/v7/izin/pengajuan/${id}`)).json.data;
  const apiAdm = (await api('sbx_admin', `/v7/izin/pengajuan/${id}`)).json.data;
  ok(apiW.pengajuan.status === 'Disetujui' && apiAdm.pengajuan.status === 'Disetujui' && apiW.pengajuan.id === id && statusDb(id) === 'Disetujui', 'API wali/admin dan DB: status dan ID sama');
  await wb.goto(app + `/izin-wali/${id}`);
  await tunggu(wb, 'Disetujui murobi');
  ok(!(await teks(wb)).includes('Sesuai jadwal pulang'), 'Wali kedua: status Disetujui tanpa alasan keputusan');
  const pw = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const wp = await pw.newPage();
  await wp.route('**/*', r => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.fallback() : r.abort()));
  await wp.goto(`${web}/portal/index.php`);
  await wp.fill('#username', 'sbx_v7_wali_a');
  await wp.fill('#password', PASS);
  await Promise.all([wp.waitForURL(/\/portal\/index\.php/), wp.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  await wp.goto(`${web}/portal/izin_wali_detail.php?id=${id}`);
  const tweb = (await wp.locator('body').innerText()).replace(/\s+/g, ' ');
  ok(tweb.includes('Disetujui murobi') && tweb.includes(`${PENANDA} acara keluarga, dijemput 15.00`), 'Website wali: status dan isi terakhir sama dengan aplikasi');
  await wp.goto(`${web}/portal/izin_wali.php`);
  const barisWeb = await wp.locator(`a[href*="izin_wali_detail.php?id=${id}"]`).count();
  ok(barisWeb >= 1 && Number(sql(`SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali' AND santri_id=${sSatu}`)) === 1, 'Website memuat pengajuan dari aplikasi; tetap satu baris');
  await pw.close();

  console.log('\n=== P-G. Admin menetapkan pembimbing; wali membatalkan ===');
  const r2 = await api('sbx_v7_wali_a', '/v7/izin/wali/pengajuan', { santri_id: sDua, tgl_izin: '2027-11-10', tgl_kembali: '2027-11-10', alasan: `${PENANDA} periksa gigi`, idempotency_key: kunci('ambigu') });
  const id2 = r2.json.data.id;
  ok(r2.json.data.status === 'Perlu Penetapan Pembimbing', 'Dua kandidat pembimbing → menunggu admin');
  const ad = await halamanApp('sbx_admin');
  await bukaPintu(ad);
  await tunggu(ad, 'Perlu tindakan admin');
  await ad.getByRole('button', { name: `Buka pengajuan izin SBX V7 Santri Dua nomor ${id2}` }).click();
  await tunggu(ad, 'Tetapkan pembimbing');
  await ad.getByRole('radio', { name: 'SBX V7 Pembimbing Dua' }).click();
  await ad.getByLabel('Alasan penetapan').fill('Pembimbing kelas santri');
  await lebar(ad, 'Rincian admin (penetapan)');
  await foto(ad, 'admin-penetapan');
  await tombol(ad, 'Tetapkan pembimbing').click();
  await tunggu(ad, 'Pembimbing ditetapkan');
  ok(statusDb(id2) === 'Menunggu Pembimbing' && Number(sql(`SELECT COUNT(*) FROM izin_pembimbing_penetapan WHERE pengajuan_id=${id2}`)) === 1, 'Admin menetapkan pembimbing sah dari aplikasi');
  await ad.context().close();
  await w.goto(app + `/izin-wali/${id2}`);
  await tunggu(w, 'Batalkan pengajuan');
  await w.getByLabel('Alasan pembatalan').fill('Jadwal berubah');
  await tombol(w, 'Batalkan pengajuan').click();
  await tunggu(w, 'Yakin membatalkan');
  const sebelumB = postV7.length;
  await tombol(w, 'Ya, batalkan pengajuan').dblclick();
  await tunggu(w, 'Pengajuan dibatalkan');
  ok(postV7.length - sebelumB === 1 && statusDb(id2) === 'Dibatalkan', 'Konfirmasi + ketukan ganda batal → satu POST, status Dibatalkan');

  console.log('\n=== P-H. Pencabutan hak, offline, sesi ===');
  const r3 = await api('sbx_v7_wali_a', '/v7/izin/wali/pengajuan', { santri_id: sCadangan, tgl_izin: '2027-12-01', tgl_kembali: '2027-12-01', alasan: `${PENANDA} takziah`, idempotency_key: kunci('cabut') });
  const id3 = r3.json.data.id;
  await pb.goto(app + `/izin-wali/${id3}`);
  await tunggu(pb, 'Teruskan ke murobi');
  const tugas = sql("SELECT pa.id FROM pembimbing_assignments pa JOIN users u ON u.pengurus_id = pa.pengurus_id JOIN kamar k ON k.id = pa.kamar_id WHERE u.username='sbx_v7_pembimbing_satu' AND k.nama_kamar='SBX V7 Kamar Satu' AND pa.is_active=1 LIMIT 1");
  sql(`UPDATE pembimbing_assignments SET is_active = 0 WHERE id = ${tugas}`);
  await tombol(pb, 'Teruskan ke murobi').click();
  await tunggu(pb, 'Data belum dapat ditampilkan');
  const tpb = await teks(pb);
  ok(!tpb.includes(PENANDA) && !tpb.includes('SBX V7 Santri Cadangan') && statusDb(id3) === 'Menunggu Pembimbing', 'Penugasan dicabut saat layar terbuka → kirim ditolak, isi ditutup, tanpa mutasi');
  sql(`UPDATE pembimbing_assignments SET is_active = 1 WHERE id = ${tugas}`);
  await pb.context().close();
  const rel = sql(`SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username='sbx_v7_wali_b' AND sw.santri_id=${sSatu} AND sw.archived_at IS NULL`);
  sql(`UPDATE santri_wali SET archived_at = NOW() WHERE id = ${rel}`);
  await tombol(wb, 'Muat ulang').click();
  await tunggu(wb, 'Data belum dapat ditampilkan');
  ok(!(await teks(wb)).includes('SBX V7 Santri Satu'), 'Relasi wali kedua dicabut → muat ulang menampilkan penolakan tanpa isi');
  sql(`UPDATE santri_wali SET archived_at = NULL WHERE id = ${rel}`);
  await wb.context().close();
  await w.goto(app + `/izin-wali/${id}`);
  await tunggu(w, 'Keputusan akhir');
  jaringan.putus = true;
  await tombol(w, 'Muat ulang').click();
  await tunggu(w, 'offline atau server tidak terjangkau', 20000);
  ok(true, 'Offline → pesan "Perangkat sedang offline…" dengan tombol coba lagi');
  jaringan.putus = false;
  await tombol(w, 'Coba lagi').click();
  await tunggu(w, 'Keputusan akhir');
  ok(true, 'Kembali online → coba lagi memuat rincian');
  ok(!(await penyimpanan(w)).includes(PENANDA) && !(await penyimpanan(w)).includes('SBX V7'), 'Wali: local/sessionStorage tanpa alasan izin atau nama santri');
  const wId = sql("SELECT id FROM users WHERE username='sbx_v7_wali_a'");
  sql(`UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ${wId} AND revoked_at IS NULL`);
  await tombol(w, 'Muat ulang').click();
  await w.waitForURL(u => u.pathname === '/login', { timeout: 15000 }).catch(() => {});
  ok(new URL(w.url()).pathname === '/login', 'Token dicabut/kedaluwarsa → aplikasi kembali ke layar masuk');
  await w.goto(app + `/izin-wali/${id}`);
  await w.waitForURL(u => u.pathname === '/login', { timeout: 15000 }).catch(() => {});
  ok(new URL(w.url()).pathname === '/login' && !(await teks(w)).includes('SBX V7 Santri Satu'), 'Tautan rincian tanpa sesi → layar masuk, tanpa isi');
  await w.context().close();

  console.log('\n=== P-I. Konsol dan jaringan ===');
  const semuaTokens = Object.values(tokens);
  ok(!konsol.some(l => l.includes(PENANDA) || semuaTokens.some(t => l.includes(t)) || l.includes(PASS)), `Konsol (${konsol.length} baris) tanpa alasan izin, token, atau kata sandi`);
  ok(permintaanLuar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${permintaanLuar.length})`);
  ok(Number(sql("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal='whatsapp' AND event_type LIKE 'izin.wali_%'")) === 0, 'WhatsApp tidak pernah diantrekan untuk peristiwa V7');
} catch (e) {
  gagal++;
  console.log('[gagal] Pengecualian: ' + (e?.stack || e));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
