import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Uji native Android EMULATOR Fase 2 PRD-PROFIL-IMPORT (dev client Debug + Metro,
// API/DB uji lokal lewat `adb reverse`). Bukan perangkat fisik.
//
//   PIF1_DB=test_pif2_app_test bash tests/profil_impor_f1_db.sh && PIF2_DB=… php tests/profil_impor_f2_app_seed.php > ids.json
//   adb reverse tcp:8962 tcp:8962; adb reverse tcp:8963 tcp:8963   (API dan Metro)
//   adb push foto.jpg /sdcard/Pictures/ (foto contoh di galeri emulator)
//   DB_NAME=test_pif2_app_test METRO=http://127.0.0.1:8963 SEED_JSON=ids.json OUT_DIR=… node uji-profil-impor-f2-android.mjs
//
//   N-A  kartu identitas paling atas Beranda; nama panjang + empat status, teks normal dan teks sistem 2.0×
//   N-B  Profil/Edit Profil: ubah isian, nilai baru di Profil & kartu tanpa masuk ulang
//   N-C  foto lewat Android Photo Picker sistem: tanpa dialog izin, tanpa izin media/penyimpanan pada paket
//   N-D  Ganti Password: salah ditolak, berhasil; password sementara → layar khusus → Beranda
//   N-E  logcat tanpa password/bearer mentah
const db = process.env.DB_NAME || '', metro = process.env.METRO || '';
if (!/^test_.*_test$/.test(db) || !/^http:\/\/127\.0\.0\.1:\d+$/.test(metro)) throw Error('Hanya DB test_*_test dan Metro localhost');
const ADB = process.env.ADB || `${process.env.HOME}/Library/Android/sdk/platform-tools/adb`;
const PKG = 'com.ilanmochamad.alhasanApps';
const out = process.env.OUT_DIR || '/tmp/pif2-android';
fs.mkdirSync(out, { recursive: true });
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const adb = (...a) => execFileSync(ADB, a, { encoding: 'utf8', maxBuffer: 64 << 20 });
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tidur = ms => new Promise(r => setTimeout(r, ms));

function dump() {
  adb('shell', 'uiautomator', 'dump', '/sdcard/ui.xml');
  const xml = adb('shell', 'cat', '/sdcard/ui.xml');
  return [...xml.matchAll(/<node [^>]*>/g)].map(m => {
    const a = k => (m[0].match(new RegExp(`${k}="([^"]*)"`)) || [])[1] || '';
    const b = a('bounds').match(/\d+/g)?.map(Number) || [0, 0, 0, 0];
    return { text: a('text').replace(/&amp;/g, '&').replace(/&#10;/g, '\n'), desc: a('content-desc').replace(/&amp;/g, '&'), pkg: a('package'), cls: a('class'), rid: a('resource-id'), b };
  });
}
const isi = nodes => nodes.map(n => `${n.text} ${n.desc}`).join(' ').replace(/\s+/g, ' ');
async function cari(pola, { timeout = 25000, tap = false, pilih = 'first', kelas = '', luar = false } = {}) {
  const akhir = Date.now() + timeout;
  while (Date.now() < akhir) {
    const nodes = dump();
    const kena = nodes.filter(n => (pola.test(n.text) || pola.test(n.desc) || pola.test(n.rid)) && n.cls.includes(kelas) && (!luar || n.pkg !== PKG));
    if (kena.length) {
      const n = pilih === 'last' ? kena[kena.length - 1] : kena[0];
      if (tap) adb('shell', 'input', 'tap', String((n.b[0] + n.b[2]) >> 1), String((n.b[1] + n.b[3]) >> 1));
      return { n, nodes };
    }
    await tidur(800);
  }
  throw Error(`Tidak ditemukan: ${pola}`);
}
const ketuk = (pola, o = {}) => cari(pola, { ...o, tap: true });
const ketik = teks => adb('shell', 'input', 'text', teks.replace(/([#&;()<>|$'"\\ ])/g, m => (m === ' ' ? '%s' : `\\${m}`)));
async function isiKolom(pola, nilai) {
  await ketuk(pola, { kelas: 'EditText' });
  await tidur(300);
  adb('shell', 'input', 'keyevent', 'KEYCODE_MOVE_END');
  for (let i = 0; i < 6; i++) adb('shell', 'input', 'keyevent', ...Array(15).fill('KEYCODE_DEL'));
  if (nilai) ketik(nilai);
  await tidur(300);
}
const geserAtas = () => adb('shell', 'input', 'swipe', '540', '1800', '540', '700', '400');
/** BACK hanya bila keyboard tampil; tanpa keyboard BACK menutup layar. */
const tutupKeyboard = () => {
  if (/mInputShown=true|isInputViewShown=true/.test(adb('shell', 'dumpsys', 'input_method'))) adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
};
const foto = nama => fs.writeFileSync(path.join(out, `android-pif2-${nama}.png`), execFileSync(ADB, ['exec-out', 'screencap', '-p'], { maxBuffer: 64 << 20 }));
async function bukaApp() {
  adb('shell', 'am', 'force-stop', PKG);
  adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', `alhasanapps://expo-development-client/?url=${encodeURIComponent(metro)}`);
  await tidur(4000);
}
async function masuk(user, password = PASS) {
  await bukaApp();
  const awal = await cari(/Masukkan username|Assalamu.alaikum,|Ganti password sementara/, { timeout: 90000 });
  if (!/Masukkan username/.test(isi(awal.nodes))) throw Error('Sesi lama masih aktif');
  await ketuk(/Masukkan username/, { kelas: 'EditText' });
  ketik(user);
  tutupKeyboard();
  await tidur(500);
  geserAtas();
  await tidur(600);
  await ketuk(/Masukkan password/, { kelas: 'EditText' });
  ketik(password);
  // Enter memicu onSubmitEditing (Masuk) walau tombol tertutup keyboard pada teks besar.
  adb('shell', 'input', 'keyevent', 'KEYCODE_ENTER');
}
/** Ketuk kartu identitas → Profil; diulang bila ketukan jatuh sebelum layar siap. */
async function bukaProfil() {
  for (let i = 0; i < 4; i++) {
    await ketuk(/Buka profil/, { timeout: 60000 });
    try { await cari(/Perangkat & push|Data diri|Edit profil/, { timeout: 8000 }); return; } catch { await tidur(1500); }
  }
  throw Error('Profil tidak terbuka dari kartu');
}
async function keluar() {
  await bukaApp();
  await bukaProfil();
  for (let i = 0; i < 8; i++) {
    const nodes = dump();
    if (nodes.some(n => /Keluar dari aplikasi/.test(n.text + n.desc))) break;
    geserAtas(); await tidur(500);
  }
  await ketuk(/Keluar dari aplikasi/);
  await ketuk(/^(KELUAR|Keluar)$/, { pilih: 'last' });
  await cari(/Masukkan username/, { timeout: 30000 });
}

adb('logcat', '-c');
try {
  console.log('\n=== N-A. Kartu identitas, teks normal dan besar ===');
  for (const skala of ['1.0', '2.0']) {
    adb('shell', 'settings', 'put', 'system', 'font_scale', skala);
    await masuk('sbx_v5_ganda');
    const { nodes } = await cari(/Assalamu.alaikum,/, { timeout: 60000 });
    const kartuNode = nodes.find(n => /Buka profil/.test(n.desc));
    const namaPanjang = 'SBX V5 Ganda Muhammad Abdurrahman Nurhidayatullah Al-Fatih Ramadhan Syarifuddin';
    const namaNode = nodes.find(n => n.text === namaPanjang);
    ok(Boolean(namaNode), `Teks ${skala}×: nama panjang tampil utuh tanpa elipsis`);
    const s = isi(nodes);
    ok(['Guru', 'Pengurus', 'Murobi', 'Pembimbing'].every(t => nodes.some(n => n.text === t)), `Teks ${skala}×: empat status tampil`);
    ok(/Murobi · Kelas SBX V5 Kelas X/.test(s) && /Pembimbing · Kamar SBX V5 Kamar B/.test(s), `Teks ${skala}×: target Kelas dan Kamar berlabel`);
    const atas = Math.min(...nodes.filter(n => n.pkg === PKG && n.text.trim() !== '' && n.b[1] > 100).map(n => n.b[1]));
    const tanggal = nodes.find(n => /^(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),/.test(n.text));
    ok(Boolean(kartuNode) && tanggal && tanggal.b[1] === atas, `Teks ${skala}×: kartu adalah isi paling atas (teks pertama di y=${atas} = tanggal kartu)`);
    if (namaNode) ok(namaNode.b[2] <= 1080 && namaNode.b[0] >= 0, `Teks ${skala}×: nama di dalam lebar layar`);
    foto(`beranda-teks-${skala}`);
    await keluar();
  }
  adb('shell', 'settings', 'put', 'system', 'font_scale', '1.0');

  console.log('\n=== N-B/N-C. Edit Profil dan foto (sbx_pengurus_a) ===');
  await masuk('sbx_pengurus_a');
  await bukaProfil();
  await cari(/Rincian penugasan aktif|Data diri/);
  foto('profil');
  await ketuk(/^Edit profil$/);
  await cari(/Diatur admin|Foto profil/);
  await isiKolom(/^Tempat lahir$/, 'Garut Native');
  tutupKeyboard();
  // TL-02: pilihan foto lewat lembar bawah "Foto profil".
  await ketuk(/^Ubah foto$/);
  await ketuk(/^Pilih foto$/);
  const pemilih = await cari(/^Photo taken on|^Foto diambil/, { timeout: 30000, luar: true });
  const pkgPemilih = [...new Set(pemilih.nodes.map(n => n.pkg))].filter(p => p && p !== PKG);
  ok(pkgPemilih.some(p => /photopicker|providers\.media|documentsui|mediaprovider/i.test(p)), `Pemilih sistem terbuka (${pkgPemilih.join(', ')})`);
  ok(!pemilih.nodes.some(n => /permissioncontroller/.test(n.pkg)) && !/Allow|Izinkan/.test(isi(pemilih.nodes)), 'Tanpa dialog izin akses galeri');
  foto('pemilih-foto');
  ok(/only have access to the photos you select|hanya akan memiliki akses/i.test(isi(pemilih.nodes)), 'Pemilih menyatakan aplikasi hanya mendapat akses foto yang dipilih');
  const target = pemilih.n;
  adb('shell', 'input', 'tap', String((target.b[0] + target.b[2]) >> 1), String((target.b[1] + target.b[3]) >> 1));
  // Pemotong (allowsEditing): konfirmasi potongan.
  await cari(/^(CROP|Crop|Potong|POTONG)$/, { timeout: 20000 });
  foto('pemotong');
  await ketuk(/^(CROP|Crop|Potong|POTONG)$/);
  await cari(/Foto profil diperbarui/, { timeout: 30000 });
  const fotoDb = sql(`SELECT foto IS NOT NULL, foto_versi FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`);
  ok(fotoDb.startsWith('1\t'), `Foto dari pemilih sistem terunggah (DB foto, versi ${fotoDb.split('\t')[1]})`);
  await cari(/Foto profil SBX Pengurus A/);
  ok(true, 'Foto tampil di Edit Profil');
  await tidur(1000);
  const sisaCache = String(adb('shell', 'run-as', PKG, 'ls', '-1', 'cache/ImagePicker').split('\n').filter(b => b.trim() !== '').length);
  ok(sisaCache === '0', `Salinan lokal pemilih/pemotong dibuang setelah unggah (sisa ${sisaCache} berkas di cache/ImagePicker)`);
  foto('edit-foto');
  for (let i = 0; i < 4; i++) { geserAtas(); await tidur(400); }
  await ketuk(/^Simpan perubahan$/);
  await cari(/Garut Native/, { timeout: 25000 });
  ok(sql(`SELECT tempat_lahir FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`) === 'Garut Native', 'Tempat lahir tersimpan dan tampil di Profil tanpa masuk ulang');
  const dump1 = adb('shell', 'dumpsys', 'package', PKG);
  const izinMedia = dump1.match(/android\.permission\.(READ_MEDIA_\w+|READ_EXTERNAL_STORAGE|WRITE_EXTERNAL_STORAGE|CAMERA)[^\n]*/g) || [];
  ok(izinMedia.length === 0, `Paket tidak meminta/memiliki izin media, penyimpanan, atau kamera (${izinMedia.join('; ') || 'tidak ada'})`);
  await bukaApp();
  await cari(/Foto profil SBX Pengurus A/, { timeout: 60000 });
  ok(true, 'Kartu Beranda menampilkan foto baru');
  foto('beranda-foto');

  console.log('\n=== N-D. Ganti Password ===');
  await bukaProfil();
  for (let i = 0; i < 6 && !dump().some(n => /^Ganti password/.test(n.desc + n.text)); i++) { geserAtas(); await tidur(400); }
  await ketuk(/^Ganti password/);
  await cari(/Password saat ini/);
  await isiKolom(/^Password saat ini$/, 'SalahSekali123');
  await isiKolom(/^Password baru$/, 'NativeBaru2026');
  await isiKolom(/^Ulangi password baru$/, 'NativeBaru2026');
  tutupKeyboard();
  await ketuk(/^Simpan password baru$/);
  await cari(/Password saat ini tidak benar/);
  ok(true, 'Password saat ini salah ditolak server');
  await isiKolom(/^Password saat ini$/, PASS);
  tutupKeyboard();
  await ketuk(/^Simpan password baru$/);
  await cari(/Password berhasil diganti/);
  ok(execFileSync('php', ['-r', `echo password_verify('NativeBaru2026', '${sql(`SELECT password FROM users WHERE id=${ids.pengurus_a}`)}') ? 'ya' : 'tidak';`], { encoding: 'utf8' }) === 'ya', 'Ganti password berhasil: hash baru berlaku');
  foto('password-berhasil');
  await keluar();

  console.log('\n=== N-D. Password sementara (sbx_murobi_c) ===');
  await masuk('sbx_murobi_c', 'Sementara#2026A');
  await cari(/Ganti password sementara/, { timeout: 60000 });
  const wajib = dump();
  ok(!wajib.some(n => n.text === 'Beranda' || n.desc === 'Beranda'), 'Hanya layar Ganti Password, tanpa tab menu');
  foto('password-sementara');
  await isiKolom(/^Password saat ini$/, 'Sementara#2026A');
  await isiKolom(/^Password baru$/, 'NativeTetap2026');
  await isiKolom(/^Ulangi password baru$/, 'NativeTetap2026');
  tutupKeyboard();
  await ketuk(/^Simpan dan lanjutkan$/);
  await cari(/Assalamu.alaikum,/, { timeout: 30000 });
  ok(sql(`SELECT force_password_change FROM users WHERE id=${ids.wajib}`) === '0', 'Sesudah diganti: Beranda terbuka, wajib ganti dilepas');
  await keluar();

  console.log('\n=== N-E. Logcat ===');
  const log = adb('logcat', '-d');
  fs.writeFileSync(path.join(out, 'logcat-ringkas.txt'), `${log.split('\n').length} baris\n`);
  ok(!/NativeBaru2026|NativeTetap2026|Sementara#2026A|Sandbox#123/.test(log), 'Logcat tanpa password');
  ok(!/Bearer [0-9a-f]{32,}/.test(log), 'Logcat tanpa bearer mentah');
} catch (e) {
  gagal++;
  console.log(`[gagal] Pengecualian: ${e.message}`);
  foto('galat');
} finally {
  adb('shell', 'settings', 'put', 'system', 'font_scale', '1.0');
}
console.log(`\nHasil: ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
