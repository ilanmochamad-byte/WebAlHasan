import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi (Expo WEB, Chromium) — pencarian santri dan tab kelas asal
// IBTIDA PA/PI pada absensi, rincian laporan, dan rincian kehadiran Keamanan
// (paket 5 November 2026). API/DB uji kelas gabungan nyata; bukan native.
//
//   JKG_DB=test_pcs_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh
//   JKG_DB=test_pcs_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
//   DB_NAME=test_pcs_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 npx expo start --web --port 8098 --clear &)
//   (cd tests/browser && DB_NAME=test_pcs_app_test PCS_WEB=http://127.0.0.1:8961 PCS_APP=http://127.0.0.1:8098 SEED_JSON=ids.json OUT_DIR=… node uji-pencarian-santri-absensi-app.mjs)
//
//   A-1 tab Semua/PA/PI berhitungan = API (`task.classes`, `students[].class`); tiap tab hanya kelompoknya
//   A-2 cari nama/NIS, huruf besar/kecil, potongan NIS, hapus pencarian, hasil kosong + "Tampilkan semua"
//   A-3 nama sama, NIS berbeda: dua kartu terpisah, kelas asal benar, pencarian NIS memisahkan
//   A-4 draf lintas tab/cari: status/catatan PA & PI tetap; ringkasan dan progres menghitung seluruh peserta
//   A-5 "Semua hadir (N)": label/keterangan eksplisit, berlaku untuk peserta tersembunyi
//   A-6 simpan dari tab PI + kata kunci aktif: payload seluruh peserta unik tepat sekali; DB per student_id;
//       satu absensi guru; muat ulang menampilkan status/catatan yang sama
//   A-7 kelas asal hilang/di luar daftar → "Kelas belum tercatat" (tidak ditebak PA/PI)
//   A-8 gabungan non-IBTIDA: tab memakai nama kelas lengkap
//   A-9 server lama tanpa `classes`/`class`: tanpa tab, cari tetap bekerja, simpan seluruh peserta
//   A-10 satu kelas: tanpa tab; cari bekerja; simpan dua santri
//   A-11 rincian laporan guru dan rincian kehadiran Keamanan: cari + tab hanya baca
//   A-12 375 px tanpa gulir horizontal konten; nol permintaan keluar 127.0.0.1; konsol tanpa token
const web = process.env.PCS_WEB, app = process.env.PCS_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/pcs-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const G = 'sbx_guru_biasa', K = ids.keamanan_user;
const T = ids.tanggal, JG = ids.jadwal_gabungan, JS = ids.jadwal_satu, NK = ids.nama_kelas;
const PA = ids.santri_nama.PA, PI = ids.santri_nama.PI;

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const angka = q => Number(sql(q));
const tokens = {};
async function api(user, p, init = {}) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-pcs-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { ...init, headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json', ...(init.headers || {}) } });
  return { status: r.status, json: await r.json().catch(() => null) };
}

// Nama sama, NIS berbeda: santri PI kedua diberi nama santri PA pertama.
const KEMBAR = PA[0].nama;
sql(`UPDATE santri SET nama_santri='${KEMBAR.replace(/'/g, "''")}' WHERE id=${PI[1].id}`);
const nisDari = id => sql(`SELECT nis FROM santri WHERE id=${id}`);

// Pengubah respons GET pertemuan (hanya untuk A-7/A-8/A-9).
const ubah = { mode: null };
const kiriman = [];
const browser = await chromium.launch({ headless: true });
const konsol = [], luar = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const metode = req.method();
    const cors = { 'Access-Control-Allow-Origin': app };
    if (metode === 'OPTIONS') return route.fulfill({ status: 204, headers: { ...cors, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (metode === 'PUT' && /\/meetings\/\d+\/attendance$/.test(u.pathname)) kiriman.push(JSON.parse(req.postData() || '{}'));
    const response = await route.fetch();
    if (ubah.mode && metode === 'GET' && /^\/api\/v1\/meetings\/\d+$/.test(u.pathname)) {
      const json = await response.json();
      const d = json.data;
      if (ubah.mode === 'tanpa-kelas') {
        d.students[0].class = null;                       // kelas asal hilang
        d.students[1].class = { id: 999999, name: 'Kelas lain' }; // di luar kelas pertemuan
      } else if (ubah.mode === 'non-ibtida') {
        d.task.classes = d.task.classes.map((c, i) => ({ ...c, name: i === 0 ? 'Tahfidz Pagi' : 'Hadits Sore' }));
      } else if (ubah.mode === 'server-lama') {
        delete d.task.classes;
        d.students.forEach(s => delete s.class);
      }
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
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-pcs-${nama}-375.png`), fullPage: true });
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
const cari = p => p.getByPlaceholder('Cari nama atau NIS');
const tab = (p, label) => p.getByRole('tab', { name: new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} · \\d+$`) });
const kartu = (p, nis) => p.locator('div').filter({ has: p.getByText(new RegExp(`^NIS ${nis}( ·|$)`)) }).filter({ has: p.getByRole('radiogroup') }).last();
// RN web memetakan accessibilityState.selected ke aria-selected (juga untuk radio).
const terpilih = async loc => (await loc.getAttribute('aria-checked')) === 'true' || (await loc.getAttribute('aria-selected')) === 'true';
const nisTampil = async p => [...(await teks(p)).matchAll(/NIS (\S+)/g)].map(m => m[1]);
const lebar = async (p, nama) => {
  const selisih = await p.evaluate(() => {
    const w = document.documentElement.clientWidth;
    const bilah = [...document.querySelectorAll('div')].filter(d => /^Al Hasan\s+Beranda/.test(d.innerText || '') && (d.innerText || '').includes('Profil')).pop() ?? null;
    let maks = w;
    for (const e of document.querySelectorAll('body *')) {
      if (bilah && bilah.contains(e)) continue;
      const r = e.getBoundingClientRect();
      if (r.width > 0) maks = Math.max(maks, r.right);
    }
    return Math.round(maks - w);
  });
  ok(selisih <= 1, `${nama}: konten tanpa gulir horizontal pada 375 px (selisih ${selisih}px)`);
};
async function bukaPertemuan(g, jadwal) {
  await g.goto(app + `/schedule/${jadwal}?date=${T}`);
  await tombol(g, 'Buka pertemuan').waitFor({ timeout: 20000 });
  await tombol(g, 'Buka pertemuan').click();
  await g.waitForURL(u => u.pathname.startsWith('/meeting/'), { timeout: 20000 });
  return Number(new URL(g.url()).pathname.split('/').pop());
}

try {
  const g = await halamanApp(G);
  console.log('\n=== A-1. Tab Semua/PA/PI ===');
  const MG = await bukaPertemuan(g, JG);
  await tunggu(g, 'Santri · 5 orang');
  const rinci = (await api(G, `/meetings/${MG}`)).json.data;
  const nisPA = rinci.students.filter(s => s.class?.id === ids.kelas.PA).map(s => s.nis);
  const nisPI = rinci.students.filter(s => s.class?.id === ids.kelas.PI).map(s => s.nis);
  ok(rinci.task.classes.length === 2 && nisPA.length === 3 && nisPI.length === 2, 'API: dua kelas pertemuan; kelas asal 3 PA + 2 PI');
  ok(await tab(g, 'Semua').count() === 1 && await tab(g, 'PA').count() === 1 && await tab(g, 'PI').count() === 1, 'Tab Semua, PA, PI tampil');
  ok((await tab(g, 'Semua').innerText()).includes('5') && (await tab(g, 'PA').innerText()).includes('3') && (await tab(g, 'PI').innerText()).includes('2'), 'Hitungan tab = peserta unik (5/3/2)');
  ok(await g.getByRole('tab', { name: /Kelas belum tercatat/ }).count() === 0, 'Tanpa tab "Kelas belum tercatat" bila semua kelas asal cocok');
  ok(await terpilih(tab(g, 'Semua')), 'Tab awal = Semua');
  let t = await teks(g);
  ok(t.toLowerCase().includes(`${NK.PA.toLowerCase()} 3 santri`) && t.toLowerCase().includes(`${NK.PI.toLowerCase()} 2 santri`), 'Tampilan Semua memiliki judul pemisah per kelas');
  await foto(g, 'tab-semua');
  await lebar(g, 'Absensi tab Semua');
  await tab(g, 'PA').click();
  ok(JSON.stringify((await nisTampil(g)).sort()) === JSON.stringify([...nisPA].sort()), 'Tab PA hanya peserta kelas asal PA');
  await tab(g, 'PI').click();
  ok(JSON.stringify((await nisTampil(g)).sort()) === JSON.stringify([...nisPI].sort()), 'Tab PI hanya peserta kelas asal PI');
  ok((await teks(g)).includes('Menampilkan 2 dari 5 santri'), 'Keterangan jumlah tampil');
  await foto(g, 'tab-pi');

  console.log('\n=== A-2. Cari nama/NIS ===');
  await tab(g, 'Semua').click();
  const satuPA = rinci.students.find(s => s.nis === nisPA[1]);
  await cari(g).fill(satuPA.name.toUpperCase());
  ok(JSON.stringify(await nisTampil(g)) === JSON.stringify(rinci.students.filter(s => s.name.toLowerCase() === satuPA.name.toLowerCase()).map(s => s.nis)) , 'Cari nama huruf besar menemukan santri tanpa menggulir');
  await cari(g).fill(satuPA.nis.slice(-3));
  ok((await nisTampil(g)).includes(satuPA.nis), 'Cari potongan NIS');
  await cari(g).fill('zzz-tidak-ada');
  await tunggu(g, 'Tidak ada santri yang cocok');
  ok((await nisTampil(g)).length === 0, 'Hasil kosong menampilkan pesan');
  await g.getByRole('button', { name: 'Tampilkan semua santri' }).click();
  ok((await nisTampil(g)).length === 5 && await cari(g).inputValue() === '', '"Tampilkan semua santri" mengosongkan pencarian');
  await cari(g).fill(satuPA.nis);
  await g.getByRole('button', { name: 'Hapus pencarian' }).click();
  ok((await nisTampil(g)).length === 5, 'Tombol hapus pencarian menampilkan kembali semua peserta');

  console.log('\n=== A-3. Nama sama, NIS berbeda ===');
  await cari(g).fill(KEMBAR.toLowerCase());
  const kembar = rinci.students.filter(s => s.name === KEMBAR);
  ok(kembar.length === 2 && JSON.stringify((await nisTampil(g)).sort()) === JSON.stringify(kembar.map(s => s.nis).sort()), 'Dua santri bernama sama tampil sebagai dua kartu');
  t = await teks(g);
  ok(kembar.every(s => t.includes(`NIS ${s.nis} · Kelas ${s.class.name}`)), 'Kelas asal masing-masing benar (PA dan PI)');
  await cari(g).fill(kembar[1].nis);
  ok(JSON.stringify(await nisTampil(g)) === JSON.stringify([kembar[1].nis]), 'Cari NIS memisahkan santri bernama sama');
  await cari(g).fill('');

  console.log('\n=== A-4. Draf lintas tab dan pencarian ===');
  const sPA = rinci.students.find(s => s.nis === nisPA[0]), sPI = rinci.students.find(s => s.nis === nisPI[0]);
  await tab(g, 'PA').click();
  await kartu(g, sPA.nis).getByRole('radio', { name: 'Sakit' }).click();
  await kartu(g, sPA.nis).getByPlaceholder('Catatan santri (opsional)').fill('Catatan PA uji');
  await tab(g, 'PI').click();
  await kartu(g, sPI.nis).getByRole('radio', { name: 'Izin' }).click();
  await kartu(g, sPI.nis).getByPlaceholder('Catatan santri (opsional)').fill('Catatan PI uji');
  await cari(g).fill(sPA.nis);
  ok((await nisTampil(g)).length === 0, 'Tab PI + NIS santri PA: tidak tampil (saringan digabung)');
  await tab(g, 'Semua').click();
  ok(await terpilih(kartu(g, sPA.nis).getByRole('radio', { name: 'Sakit' }))
    && await kartu(g, sPA.nis).getByPlaceholder('Catatan santri (opsional)').inputValue() === 'Catatan PA uji', 'Draf PA tetap setelah pindah tab dan mencari');
  await cari(g).fill('');
  ok(await terpilih(kartu(g, sPI.nis).getByRole('radio', { name: 'Izin' }))
    && await kartu(g, sPI.nis).getByPlaceholder('Catatan santri (opsional)').inputValue() === 'Catatan PI uji', 'Draf PI tetap setelah pindah tab dan mencari');
  await tab(g, 'PA').click();
  t = await teks(g);
  ok(/Hadir\s*3/.test(t.replace(/\s+/g, ' ')) || t.includes('3 Hadir') || t.includes('Hadir 3'), 'Ringkasan tetap menghitung seluruh peserta saat tab PA (3 Hadir)');
  ok(t.includes('Santri · 5 orang'), 'Judul jumlah tetap seluruh peserta');

  console.log('\n=== A-5. Semua hadir ===');
  ok(await tombol(g, 'Semua hadir (5)').count() === 1, 'Tombol berlabel "Semua hadir (5)"');
  ok(t.includes('berlaku untuk seluruh 5 santri pertemuan ini, termasuk yang tidak sedang ditampilkan'), 'Keterangan jangkauan Semua hadir eksplisit');
  // Uji jangkauan di salinan draf: tandai semua lalu kembalikan isian A-4.
  await tombol(g, 'Semua hadir (5)').click();
  await tab(g, 'PI').click();
  ok(await terpilih(kartu(g, sPI.nis).getByRole('radio', { name: 'Hadir' })), 'Semua hadir dari tab PA juga mengubah santri PI yang tersembunyi');
  ok(await kartu(g, sPI.nis).getByPlaceholder('Catatan santri (opsional)').inputValue() === 'Catatan PI uji', 'Semua hadir hanya mengubah status, catatan tetap');
  await kartu(g, sPI.nis).getByRole('radio', { name: 'Izin' }).click();
  await tab(g, 'PA').click();
  await kartu(g, sPA.nis).getByRole('radio', { name: 'Sakit' }).click();

  console.log('\n=== A-6. Simpan dari tab tersaring ===');
  await tab(g, 'PI').click();
  await cari(g).fill(sPI.nis);
  await foto(g, 'sebelum-simpan-tersaring');
  await tombol(g, 'Kirim absensi').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  const body = kiriman.at(-1);
  ok(kiriman.length === 1 && body.students.length === 5 && new Set(body.students.map(s => s.student_id)).size === 5
    && JSON.stringify(body.students.map(s => s.student_id).sort()) === JSON.stringify(rinci.students.map(s => s.student_id).sort()), 'Payload: seluruh 5 peserta unik tepat sekali walau hanya 1 tampil');
  const status = Object.fromEntries(body.students.map(s => [s.student_id, [s.status, s.notes]]));
  ok(status[sPA.student_id][0] === 'Sakit' && status[sPA.student_id][1] === 'Catatan PA uji' && status[sPI.student_id][0] === 'Izin' && status[sPI.student_id][1] === 'Catatan PI uji'
    && body.students.filter(s => s.status === 'Hadir').length === 3, 'Payload membawa status/catatan pada student_id yang benar');
  ok(angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MG}`) === 1 && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5
    && angka(`SELECT COUNT(DISTINCT santri_id) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5, 'DB: satu absensi guru, lima absensi santri unik');
  ok(sql(`SELECT CONCAT(status,'|',COALESCE(catatan,'')) FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${sPA.student_id}`) === 'Sakit|Catatan PA uji'
    && sql(`SELECT CONCAT(status,'|',COALESCE(catatan,'')) FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${sPI.student_id}`) === 'Izin|Catatan PI uji', 'DB: status/catatan per santri_id benar');
  await g.reload();
  await tunggu(g, 'Santri · 5 orang');
  await tab(g, 'PI').click();
  ok(await terpilih(kartu(g, sPI.nis).getByRole('radio', { name: 'Izin' }))
    && await kartu(g, sPI.nis).getByPlaceholder('Catatan santri (opsional)').inputValue() === 'Catatan PI uji', 'Muat ulang: status/catatan PI dari server');
  await tab(g, 'PA').click();
  ok(await terpilih(kartu(g, sPA.nis).getByRole('radio', { name: 'Sakit' })), 'Muat ulang: status PA dari server');

  console.log('\n=== A-7. Kelas asal hilang / di luar daftar ===');
  ubah.mode = 'tanpa-kelas';
  await g.reload();
  await tunggu(g, 'Santri · 5 orang');
  const belum = g.getByRole('tab', { name: /^Kelas belum tercatat · 2$/ });
  ok(await belum.count() === 1 && (await tab(g, 'PA').innerText()).includes('2'), 'Dua peserta tanpa kelas terverifikasi → tab "Kelas belum tercatat · 2", PA turun ke 2');
  await belum.click();
  ok((await nisTampil(g)).length === 2 && (await teks(g)).toLowerCase().includes('kelas belum tercatat 2 santri'), 'Kelompok "Kelas belum tercatat" memuat keduanya, tidak ditebak PA/PI');
  await foto(g, 'tanpa-kelas');

  console.log('\n=== A-8. Gabungan non-IBTIDA ===');
  ubah.mode = 'non-ibtida';
  await g.reload();
  await tunggu(g, 'Santri · 5 orang');
  ok(await tab(g, 'Tahfidz Pagi').count() === 1 && await tab(g, 'Hadits Sore').count() === 1 && await tab(g, 'PA').count() === 0, 'Tab memakai nama kelas lengkap (bukan PA/PI)');

  console.log('\n=== A-9. Server lama tanpa classes/class ===');
  ubah.mode = 'server-lama';
  await g.reload();
  await tunggu(g, 'Santri · 5 orang');
  ok(await g.getByRole('tab').count() === 0 && !(await teks(g)).includes('Kelas gabungan'), 'Tanpa tab dan penanda gabungan');
  await cari(g).fill(sPI.nis);
  ok(JSON.stringify(await nisTampil(g)) === JSON.stringify([sPI.nis]), 'Pencarian tetap bekerja');
  await g.getByPlaceholder('Wajib diisi untuk menyimpan koreksi').fill('Koreksi uji server lama');
  await tombol(g, 'Simpan koreksi').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(kiriman.at(-1).students.length === 5 && new Set(kiriman.at(-1).students.map(s => s.student_id)).size === 5
    && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 5 && angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MG}`) === 1, 'Koreksi menyimpan seluruh peserta, baris tidak bertambah');
  ubah.mode = null;

  console.log('\n=== A-10. Satu kelas ===');
  const MS = await bukaPertemuan(g, JS);
  await tunggu(g, 'Santri · 2 orang');
  ok(await g.getByRole('tab').count() === 0 && !(await teks(g)).includes('Semua hadir” berlaku'), 'Satu kelas: tanpa tab dan tanpa keterangan saringan');
  const nisC = ids.santri_nama.C.map(s => nisDari(s.id));
  await cari(g).fill(nisC[1]);
  ok(JSON.stringify(await nisTampil(g)) === JSON.stringify([nisC[1]]) && (await teks(g)).includes('Menampilkan 1 dari 2 santri'), 'Satu kelas: cari NIS');
  await tombol(g, 'Kirim absensi').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(kiriman.at(-1).students.length === 2 && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MS}`) === 2 && angka(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${MS}`) === 1, 'Satu kelas: simpan dua santri + satu guru');

  console.log('\n=== A-11. Rincian laporan dan Keamanan ===');
  await g.goto(app + `/report/${MG}`);
  await tunggu(g, 'Peserta (5/5)');
  ok(await tab(g, 'PA').count() === 1 && await tab(g, 'PI').count() === 1, 'Rincian laporan: tab PA/PI');
  await tab(g, 'PI').click();
  ok(JSON.stringify((await nisTampil(g)).sort()) === JSON.stringify([...nisPI].sort()), 'Rincian laporan: tab PI hanya PI');
  await cari(g).fill(sPI.nis);
  ok(JSON.stringify(await nisTampil(g)) === JSON.stringify([sPI.nis]), 'Rincian laporan: cari NIS');
  await lebar(g, 'Rincian laporan');
  const k = await halamanApp(K);
  await k.goto(app + `/keamanan/kehadiran/${MG}`);
  await tunggu(k, 'Peserta');
  await tunggu(k, NK.PA);
  ok(await tab(k, 'PA').count() === 1 && (await tab(k, 'PI').innerText()).includes('2'), 'Keamanan: tab PA/PI berhitungan');
  await tab(k, 'PA').click();
  ok(JSON.stringify((await nisTampil(k)).sort()) === JSON.stringify([...nisPA].sort()), 'Keamanan: tab PA hanya PA');
  await tab(k, 'Semua').click();
  await cari(k).fill(KEMBAR);
  ok((await nisTampil(k)).length === 2, 'Keamanan: cari nama menemukan dua santri bernama sama');
  ok(await k.getByRole('radiogroup').count() === 0, 'Keamanan: tetap hanya baca (tanpa pemilih status)');
  await foto(k, 'keamanan-rincian');
  await lebar(k, 'Rincian kehadiran Keamanan');

  console.log('\n=== A-12. Jaringan dan konsol ===');
  ok(luar.length === 0, `Tanpa permintaan keluar 127.0.0.1 (${[...new Set(luar)].join(', ') || 'nol'})`);
  ok(!konsol.some(m => /Bearer [A-Za-z0-9]|"token"\s*:/.test(m)), 'Konsol tanpa token');
} catch (e) {
  ok(false, `Galat tak terduga: ${e.message}`);
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
