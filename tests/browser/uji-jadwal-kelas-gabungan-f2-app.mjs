import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium, request as apiRequest } from 'playwright';

// Uji aplikasi Fase 2 jadwal pengajian kelas gabungan (Expo WEB dari repo
// alhasanApps + API/DB uji Fase 1 nyata). Ini Expo WEB (Chromium), bukan
// perangkat native; uji native dicatat terpisah.
//
//   JKG_DB=test_jkg_f2_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh
//   JKG_DB=test_jkg_f2_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
//   DB_NAME=test_jkg_f2_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 npx expo start --web --port 8098 --clear &)
//   (cd tests/browser && DB_NAME=test_jkg_f2_app_test JKG_WEB=http://127.0.0.1:8961 JKG_APP=http://127.0.0.1:8098 SEED_JSON=ids.json OUT_DIR=… node uji-jadwal-kelas-gabungan-f2-app.mjs)
//
//   B-1  Beranda dan daftar jadwal: gabungan memuat seluruh kelas; satu kelas tetap satu nama
//   B-2  detail jadwal gabungan = API (`classes`); server lama tanpa `classes` tetap kelas utama
//   B-3  buka pertemuan gabungan: ketukan ganda + jaringan putus sesudah server memproses → kirim ulang
//        kunci sama → tepat satu pertemuan, lima peserta, dua kelas beku
//   B-4  absensi gabungan: peserta kedua kelas berkelompok per kelas asal, label kelas asal, santri Selesai tidak ikut
//   B-5  kirim absensi: ketukan ganda + jaringan putus → kirim ulang kunci sama → satu guru + lima santri
//   B-6  pertemuan selesai: koreksi wajib beralasan, baris tidak bertambah
//   B-7  website dan laporan: satu aksi aplikasi tepat satu kali (web admin, CSV, REST laporan, rincian laporan & Keamanan aplikasi)
//   B-8  jadwal satu kelas: detail dan absensi lama tanpa judul/label kelas, satu guru + dua santri
//        (JKG_KONTROL_SATU=1 menjalankan B-8 saja, untuk kontrol pada kode mobile baseline)
//   B-9  memuat/gagal: keadaan memuat, galat 5xx → "Coba lagi"
//   B-10 sesi habis saat kirim: diarahkan ke login, tanpa perubahan data
//   B-11 guru lain: rincian jadwal/pertemuan ditolak server, tanpa nama santri; PUT langsung ditolak
//   B-12 nol permintaan keluar 127.0.0.1; 375 px tanpa gulir horizontal; penyimpanan/konsol tanpa token
const web = process.env.JKG_WEB, app = process.env.JKG_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/jkg2-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const G = 'sbx_guru_biasa';
const T = ids.tanggal;
const JG = ids.jadwal_gabungan, JS = ids.jadwal_satu;
const NK = ids.nama_kelas;
const sfx = ids.sfx;
const semuaSantri = [...ids.santri_nama.PA, ...ids.santri_nama.PI];

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const angka = q => Number(sql(q));
const tokens = {};
async function api(user, p, init = {}) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-jkg-f2-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { ...init, headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json', ...(init.headers || {}) } });
  return { status: r.status, json: await r.json().catch(() => null) };
}

// Pengendali jaringan per uji: putuskan respons SESUDAH server memproses
// (permintaan sampai, jawaban hilang), gagalkan GET, atau hapus field Fase 1.
const jaringan = { putusBuka: false, putusAbsen: false, gagalRincian: 0, tundaRincian: 0, serverLama: false };
const kiriman = { buka: [], absen: [] };
const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const metode = req.method();
    if (metode === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    const cors = { 'Access-Control-Allow-Origin': app };
    if (metode === 'POST' && /\/schedules\/\d+\/meetings$/.test(u.pathname)) {
      kiriman.buka.push({ user, path: u.pathname, key: JSON.parse(req.postData() || '{}').idempotency_key });
      if (jaringan.putusBuka) { jaringan.putusBuka = false; await route.fetch(); return route.abort('failed'); }
    }
    if (metode === 'PUT' && /\/meetings\/\d+\/attendance$/.test(u.pathname)) {
      const body = JSON.parse(req.postData() || '{}');
      kiriman.absen.push({ user, path: u.pathname, key: body.idempotency_key, body });
      if (jaringan.putusAbsen) { jaringan.putusAbsen = false; await route.fetch(); return route.abort('failed'); }
    }
    if (metode === 'GET' && /^\/api\/v1\/meetings\/\d+$/.test(u.pathname)) {
      if (jaringan.gagalRincian > 0) { jaringan.gagalRincian--; return route.fulfill({ status: 503, headers: cors, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, error: { code: 'SERVER_ERROR', message: 'Uji: server sibuk.' } }) }); }
      if (jaringan.tundaRincian > 0) { const t = jaringan.tundaRincian; jaringan.tundaRincian = 0; await new Promise(r => setTimeout(r, t)); }
    }
    const response = await route.fetch();
    if (jaringan.serverLama && metode === 'GET' && /^\/api\/v1\/schedules\/\d+$/.test(u.pathname)) {
      const json = await response.json();
      delete json.data.classes;
      return route.fulfill({ response, headers: { ...response.headers(), ...cors }, body: JSON.stringify(json) });
    }
    await route.fulfill({ response, headers: { ...response.headers(), ...cors } });
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login');
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const teksKecil = async p => (await teks(p)).toLowerCase();
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
// Bilah tab Expo web akun guru sudah meluap sejak baseline mobile (temuan lama
// TF4-1, khusus web); konten layar diukur tanpa bilah itu, luapan bilah dicatat.
const lebar = async (p, nama) => {
  const [konten, bilah] = await p.evaluate(() => {
    const w = document.documentElement.clientWidth;
    const tab = [...document.querySelectorAll('div')].filter(d => /^Al Hasan\s+Beranda/.test(d.innerText || '') && (d.innerText || '').includes('Profil')).pop() ?? null;
    let maks = w, maksTab = w;
    for (const e of document.querySelectorAll('body *')) {
      const r = e.getBoundingClientRect();
      if (r.width === 0) continue;
      if (tab && tab.contains(e)) maksTab = Math.max(maksTab, r.right); else maks = Math.max(maks, r.right);
    }
    return [Math.round(maks - w), Math.round(maksTab - w)];
  });
  ok(konten <= 1, `${nama}: konten tanpa gulir horizontal pada 375 px (selisih ${konten}px${bilah > 0 ? `; bilah tab web TF4-1 ${bilah}px, temuan lama` : ''})`);
};
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-jkg2-${nama}-375.png`), fullPage: true });
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const pertemuan = j => sql(`SELECT id FROM pertemuan_pengajian WHERE jadwal_id=${j} AND tanggal_pertemuan='${T}'`).split('\n').filter(Boolean).map(Number);
const kartuSantri = (p, nama) => p.locator('div').filter({ has: p.getByText(nama, { exact: true }) }).filter({ has: p.getByRole('radiogroup') }).last();
const labelGab = `${NK.PA}, ${NK.PI}`;

// B-8 dipisah agar dapat dijalankan sendiri pada kode baseline (JKG_KONTROL_SATU=1):
// jadwal satu kelas harus berperilaku sama sebelum dan sesudah Fase 2.
async function alurSatuKelas(g) {
  let t;
  await g.goto(app + `/schedule/${JS}?date=${T}`);
  await tunggu(g, 'Daftar santri akan dibekukan dari keanggotaan kelas saat pertemuan dibuka.');
  t = await teks(g);
  ok(!t.includes('Kelas gabungan') && t.includes(`${NK.C} · Uji`), 'Detail satu kelas: baris "Kelas" lama, tanpa penanda gabungan');
  await foto(g, 'detail-satu');
  await tombol(g, 'Buka pertemuan').click();
  await g.waitForURL(u => u.pathname.startsWith('/meeting/'), { timeout: 20000 });
  const MS = Number(new URL(g.url()).pathname.split('/').pop());
  await tunggu(g, 'Santri · 2 orang');
  t = await teks(g);
  ok(!t.includes('Kelas gabungan') && !t.includes('· Kelas ') && !(await teksKecil(g)).includes(`${NK.C.toLowerCase()} 2 santri`), 'Satu kelas: tanpa penanda gabungan, judul kelompok, atau label kelas per santri');
  ok(ids.santri_nama.C.every(s => t.includes(s.nama)) && t.includes(`· ${NK.C}`), 'Dua santri kelas C, judul kelas C');
  await foto(g, 'absensi-satu');
  await tombol(g, 'Konfirmasi dan kirim').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(pertemuan(JS).length === 1 && angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MS}`) === 1 && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MS}`) === 2, 'Satu pertemuan, satu guru + dua santri');
  ok(angka(`SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id=${MS}`) === 1, 'Satu kelas dibekukan');
}

try {
  if (process.env.JKG_KONTROL_SATU === '1') {
    console.log('\n=== B-8 (kontrol). Jadwal satu kelas saja ===');
    await alurSatuKelas(await halamanApp(G));
    throw Object.assign(new Error('selesai'), { selesai: true });
  }
  console.log('\n=== B-1. Beranda dan daftar jadwal ===');
  const g = await halamanApp(G);
  await tunggu(g, `Nahwu ${sfx}`);
  let t = await teks(g);
  ok(t.includes(`Nahwu ${sfx} · ${labelGab}`) || t.includes(`Nahwu ${sfx} — ${labelGab}`), 'Beranda: sesi gabungan menampilkan kedua kelas');
  ok(t.includes(`Fiqih ${sfx} · ${NK.C}`) || t.includes(`Fiqih ${sfx} — ${NK.C}`), 'Beranda: sesi satu kelas tetap satu nama kelas');
  await foto(g, 'beranda');
  await g.goto(app + '/schedules');
  await tunggu(g, `Nahwu ${sfx} · ${labelGab}`);
  t = await teks(g);
  ok(t.includes(`Fiqih ${sfx} · ${NK.C}`) && !t.includes(`Fiqih ${sfx} · ${NK.C},`), 'Daftar jadwal: satu kelas tanpa kelas tambahan');
  const aria = await g.getByRole('button', { name: new RegExp(`^Nahwu ${sfx}, ${labelGab}`) }).count();
  ok(aria === 1, 'Label aksesibilitas kartu gabungan memuat kedua kelas');
  await lebar(g, 'Daftar jadwal');
  await foto(g, 'jadwal');

  console.log('\n=== B-2. Detail jadwal ===');
  const apiJadwal = (await api(G, `/schedules/${JG}?date=${T}`)).json.data;
  ok(apiJadwal.classes.length === 2 && apiJadwal.classes[0].is_primary && apiJadwal.class.id === ids.kelas.PA, 'API: classes dua kelas, utama lebih dahulu; class lama = kelas utama');
  await g.goto(app + `/schedule/${JG}?date=${T}`);
  await tunggu(g, 'Kelas gabungan');
  t = await teks(g);
  ok(apiJadwal.classes.every(c => t.includes(c.name)) && t.includes('Satu pertemuan untuk 2 kelas'), 'Detail gabungan: seluruh kelas API dan keterangan satu pertemuan');
  ok(await tombol(g, 'Buka pertemuan').count() === 1 && pertemuan(JG).length === 0, 'Menyimpan/membuka layar tidak membuka pertemuan otomatis');
  await lebar(g, 'Detail jadwal gabungan');
  await foto(g, 'detail-gabungan');
  jaringan.serverLama = true;
  await g.goto(app + `/schedule/${JG}?date=${T}`);
  await tunggu(g, `${NK.PA} · Uji`);
  t = await teks(g);
  ok(!t.includes('Kelas gabungan') && !t.includes(NK.PI), 'Server lama tanpa `classes`: tetap terbaca sebagai kelas utama');
  jaringan.serverLama = false;

  console.log('\n=== B-3. Buka pertemuan gabungan: ketukan ganda + jaringan putus ===');
  await g.goto(app + `/schedule/${JG}?date=${T}`);
  await tunggu(g, 'Kelas gabungan');
  jaringan.putusBuka = true;
  await tombol(g, 'Buka pertemuan').dblclick();
  await tunggu(g, 'server tidak terjangkau');
  ok(kiriman.buka.length === 1, `Ketukan ganda: satu POST pembukaan (${kiriman.buka.length})`);
  ok(pertemuan(JG).length === 1, 'Server sudah membuat pertemuan walau jawaban hilang');
  await tombol(g, 'Buka pertemuan').click();
  await g.waitForURL(u => u.pathname.startsWith('/meeting/'), { timeout: 20000 });
  const MG = Number(new URL(g.url()).pathname.split('/').pop());
  ok(kiriman.buka.length === 2 && kiriman.buka[0].key === kiriman.buka[1].key, 'Kirim ulang memakai kunci idempotensi yang sama');
  ok(pertemuan(JG).length === 1 && pertemuan(JG)[0] === MG, 'Tepat satu pertemuan; aplikasi membuka pertemuan yang sama');
  ok(angka(`SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=${MG}`) === 5 && angka(`SELECT COUNT(DISTINCT santri_id) FROM pertemuan_peserta WHERE pertemuan_id=${MG}`) === 5, 'Lima peserta unik (3 PA + 2 PI)');
  ok(sql(`SELECT GROUP_CONCAT(kelas_id ORDER BY kelas_id) FROM pertemuan_kelas WHERE pertemuan_id=${MG}`) === `${ids.kelas.PA},${ids.kelas.PI}`, 'Dua kelas dibekukan pada pertemuan');
  ok(angka(`SELECT COUNT(*) FROM api_idempotency_keys WHERE idempotency_key='${kiriman.buka[0].key}'`) === 1, 'Satu baris idempotensi untuk kunci itu');

  console.log('\n=== B-4. Absensi gabungan ===');
  await tunggu(g, 'Santri · 5 orang');
  t = await teks(g);
  const apiRinci = (await api(G, `/meetings/${MG}`)).json.data;
  ok(t.includes(`· ${labelGab}`) && t.includes('Kelas gabungan · 2 kelas dalam satu pertemuan'), 'Judul pertemuan memuat kedua kelas');
  ok(semuaSantri.every(s => t.includes(s.nama)) && !t.includes(ids.santri_selesai_nama), 'Peserta kedua kelas tampil; keanggotaan Selesai tidak ikut');
  const kecil = t.toLowerCase();
  const iPA = kecil.indexOf(NK.PA.toLowerCase() + ' 3 santri'), iPI = kecil.indexOf(NK.PI.toLowerCase() + ' 2 santri');
  ok(iPA >= 0 && iPI > iPA, 'Judul kelompok per kelas asal (PA 3 santri, lalu PI 2 santri)');
  const posisi = n => t.indexOf(n);
  ok(ids.santri_nama.PA.every(s => posisi(s.nama) > iPA && posisi(s.nama) < iPI) && ids.santri_nama.PI.every(s => posisi(s.nama) > iPI), 'Santri berada di bawah kelas asalnya');
  ok(apiRinci.students.every(s => t.includes(`${s.name}`) && t.includes(`NIS ${s.nis} · Kelas ${s.class.name}`)), 'Setiap santri berlabel kelas asal = API');
  await lebar(g, 'Absensi gabungan');
  await foto(g, 'absensi-gabungan');

  console.log('\n=== B-5. Kirim absensi: ketukan ganda + jaringan putus ===');
  const pi1 = ids.santri_nama.PI[0];
  await kartuSantri(g, pi1.nama).getByRole('radio', { name: 'Izin' }).click();
  jaringan.putusAbsen = true;
  await tombol(g, 'Konfirmasi dan kirim').dblclick();
  await tunggu(g, 'server tidak terjangkau');
  ok(kiriman.absen.length === 1, `Ketukan ganda: satu PUT absensi (${kiriman.absen.length})`);
  ok(kiriman.absen[0].body.students.length === 5 && new Set(kiriman.absen[0].body.students.map(s => s.student_id)).size === 5, 'Muatan: lima santri unik, satu entri guru');
  ok(angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5, 'Server sudah menyimpan walau jawaban hilang');
  await tombol(g, 'Konfirmasi dan kirim').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(kiriman.absen.length === 2 && kiriman.absen[0].key === kiriman.absen[1].key, 'Kirim ulang absensi memakai kunci yang sama');
  ok(angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MG}`) === 1 && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5, 'Satu absensi guru + lima absensi santri');
  ok(sql(`SELECT status FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${pi1.id}`) === 'Izin' && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG} AND status='Hadir'`) === 4, 'Status sesuai isian (PI1 Izin, empat Hadir)');
  ok(sql(`SELECT status FROM pertemuan_pengajian WHERE id=${MG}`) === 'Selesai', 'Pertemuan berstatus Selesai');

  console.log('\n=== B-6. Pertemuan selesai: koreksi ===');
  await tombol(g, 'Simpan koreksi').waitFor({ timeout: 15000 });
  const pa1 = ids.santri_nama.PA[0];
  await kartuSantri(g, pa1.nama).getByRole('radio', { name: 'Sakit' }).click();
  const nSebelum = kiriman.absen.length;
  await tombol(g, 'Simpan koreksi').click();
  await tunggu(g, 'Alasan koreksi wajib diisi');
  ok(kiriman.absen.length === nSebelum, 'Tanpa alasan: tidak ada permintaan ke server');
  await g.getByPlaceholder('Wajib diisi untuk menyimpan koreksi').fill('Koreksi uji Fase 2');
  await tombol(g, 'Simpan koreksi').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(kiriman.absen.length === nSebelum + 1 && kiriman.absen.at(-1).key !== kiriman.absen[0].key && kiriman.absen.at(-1).body.correction_reason === 'Koreksi uji Fase 2', 'Koreksi: satu PUT, kunci baru, alasan terkirim');
  ok(angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MG}`) === 1 && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5 && sql(`SELECT status FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${pa1.id}`) === 'Sakit', 'Koreksi memperbarui baris, tidak menambah');
  await foto(g, 'absensi-koreksi');

  console.log('\n=== B-7. Website dan laporan ===');
  const webCtx = await apiRequest.newContext({ baseURL: web });
  const csrf = (await (await webCtx.get('/portal/index.php')).text()).match(/name="_csrf" value="([^"]+)"/)?.[1] ?? '';
  await webCtx.post('/admin/cek_login.php', { form: { _csrf: csrf, username: 'sbx_admin', password: PASS } });
  const saring = `date_from=${T}&date_to=${T}&schedule_id=${JG}`;
  const lapWeb = await (await webCtx.get(`/admin/admin_laporan_absensi.php?${saring}&subject_scope=gabungan`)).text();
  ok(semuaSantri.every(s => lapWeb.split(s.nama).length - 1 === 1), 'Laporan web admin: setiap santri tepat satu baris');
  const csvTeks = await (await webCtx.get(`/admin/export_laporan_absensi.php?${saring}&subject_scope=gabungan`)).text();
  const baris = csvTeks.split('\n').filter(b => b.includes(`Nahwu ${sfx}`));
  ok(baris.length === 6 && baris.filter(b => /[,;"]Guru[,;"]/.test(b)).length === 1, `CSV: satu baris guru + lima santri (${baris.length})`);
  ok(baris.some(b => b.includes(pi1.nama) && b.includes(NK.PI) && b.includes('Izin')) && baris.some(b => b.includes(pa1.nama) && b.includes(NK.PA) && b.includes('Sakit')), 'CSV: kelas asal dan status hasil aplikasi');
  const rinciWeb = await (await webCtx.get(`/admin/laporan_absensi_detail.php?id=${MG}`)).text();
  ok(semuaSantri.every(s => rinciWeb.split(s.nama).length - 1 === 1), 'Rincian kehadiran web: setiap santri satu kali');
  const pertemuanWeb = await (await webCtx.get(`/admin/admin_pengajian.php?tab=pertemuan&date_from=${T}&date_to=${T}`)).text();
  ok(pertemuanWeb.split(`/admin/admin_pengajian.php?tab=pertemuan&amp;action=view&amp;id=${MG}`).length >= 2 || pertemuanWeb.includes(`id=${MG}`), 'Daftar pertemuan web memuat pertemuan dari aplikasi');
  await webCtx.dispose();
  const lapApi = (await api(G, `/reports?${saring}`)).json.data;
  ok(lapApi.summary.meeting_count === 1 && lapApi.summary.teacher_attendance_count === 1 && lapApi.summary.student_attendance_count === 5 && lapApi.schedules.length === 1, 'REST laporan: satu pertemuan, satu guru, lima santri, satu ringkasan jadwal');
  await g.goto(app + `/report/${MG}`);
  await tunggu(g, 'Kelas gabungan · 2 kelas dalam satu pertemuan');
  t = await teks(g);
  ok(t.includes(`Peserta (5/5)`) && semuaSantri.every(s => t.split(s.nama).length - 1 === 1) && t.includes(`Kelas ${NK.PI}`), 'Rincian laporan aplikasi: lima peserta sekali, kelas asal');
  await foto(g, 'laporan-rincian');
  const kk = await halamanApp(ids.keamanan_user);
  await kk.goto(app + `/keamanan/kehadiran/${MG}`);
  await tunggu(kk, `· ${labelGab}`);
  t = await teks(kk);
  ok(t.includes('2 kelas dalam satu pertemuan') && semuaSantri.every(s => t.split(s.nama).length - 1 === 1) && t.includes(`Kelas ${NK.PA}`), 'Rincian kehadiran Keamanan (baca saja): kedua kelas, peserta sekali, kelas asal');
  await foto(kk, 'keamanan-rincian');
  await kk.context().close();

  console.log('\n=== B-8. Jadwal satu kelas: alur lama ===');
  await alurSatuKelas(g);

  console.log('\n=== B-9. Keadaan memuat dan gagal ===');
  jaringan.tundaRincian = 2500;
  await g.goto(app + `/meeting/${MG}`);
  await tunggu(g, 'Memuat absensi');
  ok(true, 'Keadaan memuat tampil selama permintaan berjalan');
  await tunggu(g, 'Santri · 5 orang');
  jaringan.gagalRincian = 3; // GET dicoba ulang dua kali oleh klien
  await g.goto(app + `/meeting/${MG}`);
  await tunggu(g, 'Data belum dapat ditampilkan');
  t = await teks(g);
  ok(!semuaSantri.some(s => t.includes(s.nama)), 'Galat 5xx: tanpa data, pesan dan "Coba lagi"');
  await foto(g, 'gagal');
  await tombol(g, 'Coba lagi').click();
  await tunggu(g, 'Santri · 5 orang');
  ok(true, '"Coba lagi" memuat absensi');

  console.log('\n=== B-10. Sesi habis saat kirim ===');
  const statusSebelum = sql(`SELECT GROUP_CONCAT(santri_id, ':', status ORDER BY santri_id) FROM absensi_santri WHERE pertemuan_id=${MG}`);
  await kartuSantri(g, pi1.nama).getByRole('radio', { name: 'Alpa' }).click();
  await g.getByPlaceholder('Wajib diisi untuk menyimpan koreksi').fill('Koreksi sesudah sesi habis');
  sql(`UPDATE api_tokens SET revoked_at=NOW() WHERE user_id=${ids.guru_user_id} AND name<>'uji-jkg-f2-app' AND revoked_at IS NULL`);
  await tombol(g, 'Simpan koreksi').click();
  await g.waitForURL(u => u.pathname === '/login', { timeout: 20000 });
  ok(true, 'Token dicabut: aplikasi kembali ke halaman masuk');
  ok(sql(`SELECT GROUP_CONCAT(santri_id, ':', status ORDER BY santri_id) FROM absensi_santri WHERE pertemuan_id=${MG}`) === statusSebelum, 'Tanpa perubahan absensi sesudah 401');
  await g.context().close();

  console.log('\n=== B-11. Guru lain ===');
  const m = await halamanApp(ids.murobi_user);
  await m.goto(app + `/meeting/${MG}`);
  await tunggu(m, 'Data belum dapat ditampilkan');
  t = await teks(m);
  ok(!semuaSantri.some(s => t.includes(s.nama)), 'Guru lain: rincian pertemuan ditolak server, tanpa nama santri');
  await m.goto(app + `/schedule/${JG}?date=${T}`);
  await tunggu(m, 'Data belum dapat ditampilkan');
  ok(await tombol(m, 'Buka pertemuan').count() === 0, 'Guru lain: detail jadwal ditolak, tanpa tombol buka');
  const put = await api(ids.murobi_user, `/meetings/${MG}/attendance`, { method: 'PUT', body: JSON.stringify({ idempotency_key: 'jkg2-lain-' + sfx, teacher: { status: 'Hadir', notes: '' }, students: semuaSantri.map(s => ({ student_id: s.id, status: 'Alpa', notes: '' })), correction_reason: 'x' }) });
  ok([403, 404].includes(put.status) && sql(`SELECT GROUP_CONCAT(santri_id, ':', status ORDER BY santri_id) FROM absensi_santri WHERE pertemuan_id=${MG}`) === statusSebelum, `PUT langsung guru lain ditolak (${put.status}), data tetap`);
  await m.context().close();

  console.log('\n=== B-12. Privasi dan jaringan ===');
  ok(permintaanLuar.length === 0, `Nol permintaan keluar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ') || 'tidak ada'})`);
  ok(!Object.values(tokens).some(tk => konsol.some(k => k.includes(tk))), 'Konsol tanpa token API');
  ok(angka(`SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id IN (${JG},${JS}) AND tanggal_pertemuan='${T}'`) === 2 && angka(`SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id=${ids.jadwal_besok}`) === 0, 'Total: dua pertemuan (satu per jadwal hari ini), jadwal besok tidak dibuka');
} catch (e) {
  if (!e.selesai) ok(false, `Uji berhenti: ${e.message.split('\n')[0]}`);
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
