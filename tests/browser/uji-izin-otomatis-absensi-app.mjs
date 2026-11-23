import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji tampilan status "Izin" otomatis (migrasi 036, IP-1…IP-6) di aplikasi
// (Expo WEB dari repo alhasanApps, Chromium — bukan perangkat native) di atas
// API/DB uji nyata:
//
//   JKG_DB=test_iop_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh
//   JKG_DB=test_iop_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
//   DB_NAME=test_iop_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8966 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps tanpa .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8966/api/v1 npx expo start --web --port 8098 --clear &)
//   (cd tests/browser && DB_NAME=test_iop_app_test IOP_WEB=http://127.0.0.1:8966 IOP_APP=http://127.0.0.1:8098 SEED_JSON=ids.json OUT_DIR=… node uji-izin-otomatis-absensi-app.mjs)
//
//   A-1 draf awal Izin + keterangan IP-3 pada santri ber-usulan; penanda "Izin s.d. …" (375/768/1280)
//   A-2 "Semua hadir (N)" melewati santri izin, teks penjelas menyebut jumlahnya
//   A-3 ubah ke Hadir: catatan izin otomatis dikosongkan, penanda tetap; catatan yang diedit guru dipertahankan
//   A-4 "Perbarui daftar": izin baru disetujui → santri belum diubah guru menjadi Izin; isian guru tetap
//   A-5 kirim (ketukan ganda) → satu penyimpanan; DB menyimpan status pilihan guru; payload tanpa isi izin
//   A-6 rincian laporan guru dan rincian kehadiran Keamanan menampilkan keterangan
//   A-7 server lama (respons tanpa kunci `izin`) → draf "Hadir" seperti sebelumnya
const web = process.env.IOP_WEB, app = process.env.IOP_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/iop-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const G = 'sbx_guru_biasa', K = ids.keamanan_user;
const T = ids.tanggal;
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const RAHASIA = 'RAHASIA-ALASAN-' + ids.sfx;
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const dm = ymd => `${Number(ymd.slice(8, 10))} ${BULAN[Number(ymd.slice(5, 7)) - 1]}`;
const geser = (ymd, n) => { const d = new Date(ymd + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const angka = q => Number(sql(q));
const izin = (santri, dari, sampai, kapasitas = 'Murobi') => {
  sql(`INSERT INTO izin_pengajuan (santri_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus, status, asal_pengajuan, diajukan_pada)
       VALUES (${santri}, ${ids.guru_h}, ${ids.tahun}, '${dari}', '${sampai}', '${RAHASIA} alasan', '${RAHASIA} catatan', 'Disetujui', 'Pengurus', NOW())`);
  const id = angka(`SELECT MAX(id) FROM izin_pengajuan WHERE santri_id = ${santri}`);
  sql(`INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, alasan_penggantian, diputus_pada)
       VALUES (${id}, 'Disetujui', '${RAHASIA} keputusan', ${ids.admin_id}, '${kapasitas}', ${kapasitas === 'Admin Pengganti' ? `'${RAHASIA} pengganti'` : 'NULL'}, NOW())`);
  return id;
};
const ket = (id, kapasitas, sampai) => `Izin No. ${id} — disetujui ${kapasitas === 'Admin Pengganti' ? 'admin pengganti' : 'murobi'} s.d. ${dm(sampai)}`;

const [PA0, PA1, PA2] = ids.santri_nama.PA;
const [PI0, PI1] = ids.santri_nama.PI;
const iPA0 = izin(PA0.id, T, geser(T, 2));
const iPI0 = izin(PI0.id, geser(T, -1), T, 'Admin Pengganti');
const ketPA0 = ket(iPA0, 'Murobi', geser(T, 2));
const ketPI0 = ket(iPI0, 'Admin Pengganti', T);

async function apiLogin(user) {
  const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-iop' }) });
  return (await r.json()).data.token;
}
const token = await apiLogin(G);
const r0 = await fetch(`${web}/api/v1/schedules/${ids.jadwal_gabungan}/meetings`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: T, idempotency_key: 'iop-buka-' + ids.sfx }) });
const MG = (await r0.json()).data.id;
ok(sql(`SELECT izin_keterangan FROM pertemuan_peserta WHERE pertemuan_id=${MG} AND santri_id=${PA0.id}`) === ketPA0, 'Server: pertemuan dibuka dengan usulan izin beku');

const browser = await chromium.launch({ headless: true });
const galat = [], luar = [], kiriman = [];
const ubah = { serverLama: false };
async function halamanApp(user, width = 375, height = 812) {
  const ctx = await browser.newContext({ viewport: { width, height } });
  const page = await ctx.newPage();
  page.on('pageerror', e => galat.push(`${user}: ${e.message}`));
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const cors = { 'Access-Control-Allow-Origin': app };
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { ...cors, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (req.method() === 'PUT' && /\/meetings\/\d+\/attendance$/.test(u.pathname)) kiriman.push(req.postData() || '');
    const response = await route.fetch();
    if (ubah.serverLama && req.method() === 'GET' && /^\/api\/v1\/meetings\/\d+$/.test(u.pathname)) {
      const json = await response.json();
      json.data.students.forEach(s => delete s.izin);
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
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const kartu = (p, nama) => p.locator('div').filter({ has: p.getByText(nama, { exact: true }) }).filter({ has: p.getByRole('radiogroup') }).last();
const terpilih = async loc => (await loc.getAttribute('aria-checked')) === 'true' || (await loc.getAttribute('aria-selected')) === 'true';
const status = async (p, nama, s) => terpilih(kartu(p, nama).getByRole('radio', { name: s }));
const catatan = async (p, nama) => kartu(p, nama).getByPlaceholder('Catatan santri (opsional)').inputValue();
const foto = (p, nama) => p.screenshot({ path: path.join(out, `iop-${nama}.png`), fullPage: true });
const bukaMenu = async p => {
  await p.waitForTimeout(600);
  if (!(await tombol(p, 'Perbarui daftar').isVisible()) && await tombol(p, 'Tindakan lain').isVisible()) {
    await tombol(p, 'Tindakan lain').click();
    await p.waitForTimeout(500);
  }
};
const meluap = p => p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);

try {
  console.log('=== A-1 draf awal dan penanda pada 1280/768/375 ===');
  for (const [w, h] of [[1280, 900], [768, 1024], [375, 812]]) {
    const p = await halamanApp(G, w, h);
    await p.goto(`${app}/meeting/${MG}`);
    await tunggu(p, 'Santri · 5 orang');
    ok(await status(p, PA0.nama, 'Izin') && await catatan(p, PA0.nama) === ketPA0, `${w}px: santri izin murobi terisi "Izin" + keterangan IP-3`);
    ok(await status(p, PI0.nama, 'Izin') && await catatan(p, PI0.nama) === ketPI0, `${w}px: santri izin admin pengganti terisi "Izin" + keterangan`);
    ok(await status(p, PA1.nama, 'Hadir') && await catatan(p, PA1.nama) === '', `${w}px: santri tanpa izin tetap "Hadir"`);
    ok(await kartu(p, PA0.nama).getByText(`Izin s.d. ${dm(geser(T, 2))}`, { exact: true }).isVisible(), `${w}px: penanda "Izin s.d. ${dm(geser(T, 2))}" terlihat`);
    ok(!(await meluap(p)), `${w}px: tanpa luapan horizontal`);
    await foto(p, `draf-awal-${w}`);
    await p.context().close();
  }

  const g = await halamanApp(G);
  await g.goto(`${app}/meeting/${MG}`);
  await tunggu(g, 'Santri · 5 orang');
  console.log('\n=== A-2 "Semua hadir" melewati santri izin ===');
  ok(await tombol(g, 'Semua hadir (3)').isVisible(), 'Tombol "Semua hadir (3)": 5 peserta − 2 izin');
  ok((await teks(g)).includes('2 santri sedang izin pada tanggal ini'), 'Teks penjelas menyebut 2 santri izin yang dilewati');
  await kartu(g, PA1.nama).getByRole('radio', { name: 'Sakit' }).click();
  await tombol(g, 'Semua hadir (3)').click();
  ok(await status(g, PA1.nama, 'Hadir') && await status(g, PA0.nama, 'Izin') && await status(g, PI0.nama, 'Izin'), '"Semua hadir": santri lain menjadi Hadir, santri izin tetap Izin');

  console.log('\n=== A-3 guru mengubah status santri izin ===');
  await kartu(g, PA0.nama).getByRole('radio', { name: 'Hadir' }).click();
  ok(await status(g, PA0.nama, 'Hadir') && await catatan(g, PA0.nama) === '', 'Ubah ke Hadir: catatan izin otomatis dikosongkan');
  ok(await kartu(g, PA0.nama).getByText(`Izin s.d. ${dm(geser(T, 2))}`, { exact: true }).isVisible(), 'Penanda izin tetap tampil walau status diubah');
  await kartu(g, PI0.nama).getByPlaceholder('Catatan santri (opsional)').fill('Pulang lebih cepat, ikut sore');
  await kartu(g, PI0.nama).getByRole('radio', { name: 'Terlambat' }).click();
  ok(await catatan(g, PI0.nama) === 'Pulang lebih cepat, ikut sore', 'Catatan yang sudah diedit guru dipertahankan saat status diubah');
  await kartu(g, PI0.nama).getByRole('radio', { name: 'Izin' }).click();

  console.log('\n=== A-4 Perbarui daftar mengikuti usulan terbaru ===');
  const iPI1 = izin(PI1.id, T, T);
  await bukaMenu(g);
  await tombol(g, 'Perbarui daftar').click();
  await tunggu(g, 'Izin: 1 usulan izin diisi.');
  ok(await status(g, PI1.nama, 'Izin') && await catatan(g, PI1.nama) === ket(iPI1, 'Murobi', T), 'Santri yang belum diubah guru mengikuti izin baru (Izin + keterangan)');
  ok(await status(g, PA0.nama, 'Hadir') && await status(g, PA1.nama, 'Hadir') && await catatan(g, PI0.nama) === 'Pulang lebih cepat, ikut sore', 'Isian yang diubah guru tidak ditimpa');
  ok(await tombol(g, 'Semua hadir (2)').isVisible(), 'Tombol mengikuti jumlah izin terbaru: "Semua hadir (2)"');
  await foto(g, 'sesudah-perbarui-375');

  console.log('\n=== A-5 kirim dengan ketukan ganda ===');
  await tombol(g, 'Kirim absensi').dblclick();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(kiriman.length === 1 && angka(`SELECT COUNT(*) FROM audit_logs WHERE action='api.attendance.save' AND entity_id=${MG}`) === 1, 'Ketukan ganda → satu PUT, satu penyimpanan');
  const st = id => sql(`SELECT status FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${id}`);
  ok(st(PA0.id) === 'Hadir' && st(PI0.id) === 'Izin' && st(PI1.id) === 'Izin' && st(PA1.id) === 'Hadir' && st(PA2.id) === 'Hadir', 'DB: status pilihan guru tersimpan (Hadir/Izin)');
  ok(sql(`SELECT catatan FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${PI1.id}`) === ket(iPI1, 'Murobi', T), 'DB: catatan Izin = keterangan IP-3');
  ok(!kiriman.join('').includes(RAHASIA) && !(await teks(g)).includes(RAHASIA), 'Payload dan layar tanpa alasan/catatan/keputusan izin');

  console.log('\n=== A-6 rincian laporan dan Keamanan ===');
  await g.goto(`${app}/report/${MG}`);
  await tunggu(g, ketPA0);
  ok((await teks(g)).includes(ketPA0) && (await teks(g)).includes(ketPI0), 'Rincian laporan guru menampilkan keterangan izin');
  await foto(g, 'laporan-375');
  const k = await halamanApp(K);
  await k.goto(`${app}/keamanan/kehadiran/${MG}`);
  await tunggu(k, ketPA0);
  ok((await teks(k)).includes(`Izin s.d. ${dm(geser(T, 2))}`) && !(await teks(k)).includes(RAHASIA), 'Keamanan: penanda + keterangan, tanpa isi izin');
  await foto(k, 'keamanan-375');

  console.log('\n=== A-7 server lama tanpa kunci izin ===');
  const r1 = await fetch(`${web}/api/v1/schedules/${ids.jadwal_satu}/meetings`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: T, idempotency_key: 'iop-buka-satu-' + ids.sfx }) });
  const MS = (await r1.json()).data.id;
  const [C0] = ids.santri_nama.C;
  izin(C0.id, T, T);
  sql(`UPDATE pertemuan_peserta SET izin_pengajuan_id = (SELECT MAX(id) FROM izin_pengajuan WHERE santri_id=${C0.id}), izin_keterangan = 'x' WHERE pertemuan_id=${MS} AND santri_id=${C0.id}`);
  ubah.serverLama = true;
  await g.goto(`${app}/meeting/${MS}`);
  await tunggu(g, 'Santri · ');
  ok(await status(g, C0.nama, 'Hadir') && await catatan(g, C0.nama) === '' && await tombol(g, 'Semua hadir (2)').isVisible(), 'Respons tanpa `izin`: draf Hadir dan "Semua hadir" seluruh peserta (perilaku lama)');
  ubah.serverLama = false;

  ok(galat.length === 0, 'Tanpa galat halaman' + (galat.length ? ': ' + galat.slice(0, 3).join(' | ') : ''));
  ok(luar.length === 0, 'Tanpa permintaan ke host selain 127.0.0.1');
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
