import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Redesain tampilan aplikasi gelombang 2 Fase 4 — Android EMULATOR. Dua mode:
//  - METRO kosong: APK RILIS baru 1.8.0 (bundel tertanam). Catatan: APK rilis tidak
//    mengizinkan HTTP cleartext, jadi tidak dapat masuk ke API lokal http (hanya start-up).
//  - METRO=http://127.0.0.1:<port>: dev client Debug baru 1.8.0 + Metro dari salinan tanpa .env*.
// API 127.0.0.1:8971 lewat `adb reverse`. Bukan perangkat fisik dan bukan build
// distribusi (EAS). Pembantu adb/uiautomator dari uji Fase 3.
//
//   adb reverse tcp:8971 tcp:8971
//   DB_NAME=<DB Tahap 7 | V5 | Keamanan> OUT_DIR=… [SEED_JSON=…] node uji-redesain-v2-fase4-android.mjs <t7|v5|pk>
//
//   t7: pembimbing — Pembinaan via tautan saat berjalan, ← ≥ 44 dp → halaman sebelumnya/Beranda,
//       kerahasiaan "Internal", tombol kembali Android; tautan saat aplikasi MATI → ← Beranda;
//       orang tua — rapor dua versi ("Versi sebelumnya", satu "Unduh PDF rapor"); gelap + 2,0×
//   v5: petugas kesehatan — rincian kunjungan bertanda privat, ← ; FLAG_SECURE (tangkapan terlindungi)
//   pk: Keamanan — rincian izin hanya baca (tanpa tombol tindakan), ← ; guru berpenugasan; admin ditolak → ← tetap
const bagian = process.argv[2];
const db = process.env.DB_NAME || '';
if (!/^test_.*_test$/.test(db) || !['t7', 'v5', 'pk'].includes(bagian)) throw Error('Pemakaian: <t7|v5|pk>, DB test_*_test');
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const ADB = process.env.ADB || `${process.env.HOME}/Library/Android/sdk/platform-tools/adb`;
const PKG = 'com.ilanmochamad.alhasanApps';
const out = process.env.OUT_DIR || '/tmp/rtv2-f4-android';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
// METRO kosong = APK rilis (bundel tertanam). Diisi = dev client Debug + Metro 127.0.0.1.
const metro = process.env.METRO || '';
if (metro && !/^http:\/\/127\.0\.0\.1:\d+$/.test(metro)) throw Error('Metro harus 127.0.0.1');

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const adb = (...a) => execFileSync(ADB, a, { encoding: 'utf8', maxBuffer: 64 << 20 });
const tidur = ms => new Promise(r => setTimeout(r, ms));

function dump() {
  let xml = '';
  for (let i = 0; i < 4 && !xml.includes('<node'); i++) {
    try { adb('shell', 'uiautomator', 'dump', '/sdcard/ui.xml'); xml = adb('shell', 'cat', '/sdcard/ui.xml'); } catch { /* layar sedang beranimasi */ }
  }
  return [...xml.matchAll(/<node [^>]*>/g)].map(m => {
    const a = k => (m[0].match(new RegExp(`${k}="([^"]*)"`)) || [])[1] || '';
    const b = a('bounds').match(/\d+/g)?.map(Number) || [0, 0, 0, 0];
    return { text: a('text').replace(/&amp;/g, '&').replace(/&#10;/g, '\n').replace(/&apos;/g, "'"), desc: a('content-desc').replace(/&amp;/g, '&'), pkg: a('package'), cls: a('class'), rid: a('resource-id'), b };
  });
}
const isi = nodes => nodes.map(n => `${n.text} ${n.desc}`).join(' ').replace(/\s+/g, ' ');
async function cari(pola, { timeout = 25000, tap = false, kelas = '' } = {}) {
  const akhir = Date.now() + timeout;
  while (Date.now() < akhir) {
    const nodes = dump();
    // Sesudah data aplikasi dikosongkan, dev client menampilkan perkenalan menu
    // pengembang di atas layar aplikasi (kapan saja sesudah bundel dimuat).
    const lanjut = nodes.find(n => n.text === 'Continue');
    if (lanjut && nodes.some(n => /This is the developer menu/.test(n.text))) {
      adb('shell', 'input', 'tap', String((lanjut.b[0] + lanjut.b[2]) >> 1), String((lanjut.b[1] + lanjut.b[3]) >> 1));
      await tidur(1500);
      continue;
    }
    const tutup = nodes.find(n => n.desc === 'Close');
    if (nodes.some(n => /^Runtime version:/.test(n.text))) {
      // Pada teks sistem 2,0× tombol Close menu pengembang tidak ada di pohon; tombol kembali menutupnya.
      if (tutup) adb('shell', 'input', 'tap', String((tutup.b[0] + tutup.b[2]) >> 1), String((tutup.b[1] + tutup.b[3]) >> 1));
      else adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
      await tidur(1200);
      continue;
    }
    const kena = nodes.filter(n => (pola.test(n.text) || pola.test(n.desc) || pola.test(n.rid)) && n.cls.includes(kelas));
    if (kena.length) {
      const n = kena[0];
      if (tap) adb('shell', 'input', 'tap', String((n.b[0] + n.b[2]) >> 1), String((n.b[1] + n.b[3]) >> 1));
      return { n, nodes };
    }
    await tidur(800);
  }
  throw Error(`Tidak ditemukan: ${pola}`);
}
const ada = async (pola, timeout = 2500) => cari(pola, { timeout }).then(() => true, () => false);
const ketuk = (pola, o = {}) => cari(pola, { ...o, tap: true });
const ketik = teks => adb('shell', 'input', 'text', teks.replace(/([#&;()<>|$'"\\ ])/g, m => (m === ' ' ? '%s' : `\\${m}`)));
const geser = (dari, ke) => adb('shell', 'input', 'swipe', '540', String(dari), '540', String(ke), '350');
const tutupKeyboard = () => {
  if (/mInputShown=true|isInputViewShown=true/.test(adb('shell', 'dumpsys', 'input_method'))) adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
};
const foto = nama => fs.writeFileSync(path.join(out, `android-${nama}.png`), execFileSync(ADB, ['exec-out', 'screencap', '-p'], { maxBuffer: 64 << 20 }));
const skala = v => adb('shell', 'settings', 'put', 'system', 'font_scale', String(v));
const gelap = ya => adb('shell', 'cmd', 'uimode', 'night', ya ? 'yes' : 'no');

async function bukaApp() {
  adb('shell', 'am', 'force-stop', PKG);
  if (metro) adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', `alhasanapps://expo-development-client/?url=${encodeURIComponent(metro)}`);
  // Build RILIS: bundel tertanam, tanpa Metro/dev client.
  else adb('shell', 'monkey', '-p', PKG, '-c', 'android.intent.category.LAUNCHER', '1');
  await tidur(4000);
}
/** Sesi dihapus dengan mengosongkan data aplikasi (SecureStore ikut terhapus). */
async function masuk(user, password = PASS) {
  adb('shell', 'pm', 'clear', PKG);
  await bukaApp();
  await cari(/Masukkan username/, { timeout: 150000 });
  await tidur(2500); // beri waktu perkenalan menu pengembang muncul, lalu disingkirkan `cari`
  await ketuk(/Masukkan username/, { kelas: 'EditText' });
  // Emulator yang lambat dapat menjatuhkan karakter saat React memperbarui
  // TextInput terkontrol. Tunggu fokus dan periksa input sebelum mengirim.
  await tidur(800);
  for (const karakter of user) {
    ketik(karakter);
    await tidur(70);
  }
  await cari(new RegExp(`^${user}$`), { kelas: 'EditText' });
  tutupKeyboard();
  await tidur(500);
  geser(1700, 800);
  await tidur(600);
  await ketuk(/Masukkan password/, { kelas: 'EditText' });
  await tidur(800);
  for (const karakter of password) {
    ketik(karakter);
    await tidur(70);
  }
  adb('shell', 'input', 'keyevent', 'KEYCODE_ENTER');
}
const diBeranda = (timeout = 40000) => cari(/Assalamu/, { timeout });
const dp = () => Number((adb('shell', 'wm', 'density').match(/(\d+)\s*$/m) || [0, 420])[1]) / 160;
const layar = () => (adb('shell', 'wm', 'size').match(/(\d+)x(\d+)\s*$/m) || [0, 1080, 2400]).slice(1).map(Number);
/** Gulir sampai pola terlihat (maks. `maks` kali). */
async function gulirKe(pola, maks = 14) {
  for (let i = 0; i < maks; i++) {
    if (await ada(pola, 1200)) return true;
    geser(1700, 900);
    await tidur(500);
  }
  return false;
}

const tautan = async uri => { adb('shell', `am start -a android.intent.action.VIEW -d '${uri}' ${PKG}`); await tidur(2500); };
/** ← di kepala: content-desc diawali "Kembali", ≥ 44 dp, di bagian atas layar. */
async function periksaKembali(nama) {
  const { n } = await cari(/^Kembali/, { timeout: 25000 });
  const d = dp(); const w = (n.b[2] - n.b[0]) / d, h = (n.b[3] - n.b[1]) / d;
  ok(w >= 43.5 && h >= 43.5 && n.b[1] / d < 160, `${nama}: ← "${n.desc || n.text}" ${w.toFixed(1)}×${h.toFixed(1)} dp di kepala (y ${Math.round(n.b[1] / d)} dp)`);
  return n;
}
const tapNode = n => adb('shell', 'input', 'tap', String((n.b[0] + n.b[2]) >> 1), String((n.b[1] + n.b[3]) >> 1));
async function keBerandaLewatKembali(nama) {
  const n = await periksaKembali(nama); tapNode(n);
  ok(await ada(/Assalamu/, 20000), `${nama}: ← membuka Beranda`);
}

try {
  adb('reverse', 'tcp:8971', 'tcp:8971');
  if (metro) adb('reverse', `tcp:${new URL(metro).port}`, `tcp:${new URL(metro).port}`);
  skala(1.0); gelap(false);
  if (bagian === 't7') {
    const kasus = sql("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Internal' ORDER BY id LIMIT 1");
    await masuk('sbx_pengurus_a'); await diBeranda();
    ok(true, `pembimbing: masuk ke Beranda (${metro ? 'dev client Debug + Metro' : 'APK rilis'}, API lokal)`);
    ok(!(await ada(/^Kembali/, 1500)), 'Beranda: tanpa ←');
    await tautan('alhasanapps://pembinaan');
    ok(await ada(/Pelanggaran & poin/), 'tautan saat berjalan: menu Pembinaan');
    await ketuk(/^Kasus & sesi konseling/);
    ok(await ada(/Halaman 1/), 'daftar kasus terbuka dari menu');
    ok(await ada(/Internal/), 'daftar kasus: kerahasiaan "Internal" tampil (pil bergembok)');
    foto('t7-daftar-kasus');
    const n = await periksaKembali('daftar kasus'); tapNode(n);
    ok(await ada(/Pelanggaran & poin/), '← dengan riwayat kembali ke menu Pembinaan');
    await ketuk(/^Kasus & sesi konseling/); await cari(/Halaman 1/);
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK'); await tidur(1500);
    ok(await ada(/Pelanggaran & poin/), 'tombol kembali Android tetap bekerja');
    if (!metro) {
      // Tautan saat aplikasi MATI (sesi tersimpan): rincian kasus tanpa riwayat → ← Beranda.
      adb('shell', 'am', 'force-stop', PKG); await tidur(1000);
      await tautan(`alhasanapps://pembinaan/detail?jenis=kasus&id=${kasus}`);
      ok(await ada(/Muat ulang versi terbaru/, 40000), 'tautan saat aplikasi mati: rincian kasus terbuka');
      foto('t7-rincian-kasus-dingin');
      await keBerandaLewatKembali('rincian kasus (dibuka dingin)');
    } else {
      // Dev client: tautan saat aplikasi mati membuka peluncur (batas lama); diuji tautan saat berjalan.
      await tautan(`alhasanapps://pembinaan/detail?jenis=kasus&id=${kasus}`);
      ok(await ada(/Muat ulang versi terbaru/, 40000), 'tautan saat berjalan: rincian kasus terbuka');
      foto('t7-rincian-kasus');
      // Tautan saat berjalan menumpuk di atas layar yang terbuka: ← kembali ke layar itu (ada riwayat).
      const k = await periksaKembali('rincian kasus (dari tautan)'); tapNode(k);
      ok(await ada(/Pelanggaran & poin/, 20000), 'rincian kasus (dari tautan): ← kembali ke layar sebelumnya (menu Pembinaan)');
    }
    // Orang tua: rapor dua versi.
    const rapor = sql("SELECT r.id FROM v4_rapor r JOIN santri s ON s.id=r.santri_id WHERE s.nama_santri='SBX T7 Santri 01' LIMIT 1");
    await masuk('sbx_t7_ortu'); await diBeranda();
    await tautan(`alhasanapps://rapor/${rapor}`);
    ok(await ada(/Status rapor/, 30000), 'orang tua: rapor terbuka');
    ok(await gulirKe(/Unduh PDF rapor/), 'rapor: tombol Unduh PDF rapor');
    ok(await gulirKe(/Versi sebelumnya/), 'rapor: bagian "Versi sebelumnya"');
    foto('t7-rapor-wali');
    // Gelap + 2,0×.
    gelap(true); skala(2.0); await tidur(2500);
    await tautan(`alhasanapps://rapor/${rapor}`);
    ok(await ada(/Status rapor/, 30000), 'gelap + 2,0×: rapor terbuka');
    await periksaKembali('rapor gelap + 2,0×');
    foto('t7-rapor-gelap-2x');
    await tautan('alhasanapps://rapor');
    await periksaKembali('menu rapor gelap + 2,0×');
    gelap(false); skala(1.0);
  }
  if (bagian === 'v5') {
    const kid = sql("SELECT id FROM kesehatan_kunjungan ORDER BY id DESC LIMIT 1");
    await masuk('sbx_v5_petugas_f'); await diBeranda();
    await tautan(`alhasanapps://kesehatan/kunjungan/${kid}`);
    ok(await ada(/Catatan klinik/, 40000), 'petugas: rincian kunjungan terbuka');
    ok(await gulirKe(/^Privat: hanya petugas kesehatan/), 'rincian: catatan klinis bertanda privat');
    const kecil = execFileSync(ADB, ['exec-out', 'screencap', '-p'], { maxBuffer: 64 << 20 }).length;
    const jendela = adb('shell', 'dumpsys', 'window', 'windows');
    ok(/FLAG_SECURE|SECURE/.test(jendela.slice(jendela.indexOf(PKG) - 4000, jendela.indexOf(PKG) + 4000)) || kecil < 60000, `perlindungan layar Kesehatan aktif (tangkapan ${kecil} B)`);
    foto('v5-rincian-terlindungi');
    await keBerandaLewatKembali('rincian kunjungan');
    gelap(true); skala(2.0); await tidur(2500);
    await tautan('alhasanapps://kesehatan/laporan');
    ok(await ada(/Jumlah kunjungan|Laporan layanan klinik/, 40000), 'gelap + 2,0×: laporan klinik terbuka');
    await periksaKembali('laporan gelap + 2,0×');
    gelap(false); skala(1.0);
  }
  if (bagian === 'pk') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    await masuk('sbx_pengurus_biasa'); await diBeranda();
    await tautan(`alhasanapps://keamanan/izin/${ids.izin_wali}`);
    ok(await ada(/Rincian izin/, 40000) && await gulirKe(/Keputusan/), 'Keamanan: rincian izin terbuka');
    const t = isi(dump());
    ok(!/\b(Setujui|Tolak|Teruskan|Koreksi|Batalkan)\b/.test(t), 'rincian izin: tanpa tombol tindakan');
    await keBerandaLewatKembali('rincian izin Keamanan');
    // Guru dengan identitas pengurus berpenugasan Keamanan juga dapat membuka (PK-1).
    await masuk('sbx_guru_biasa'); await diBeranda();
    await tautan('alhasanapps://keamanan');
    ok(await ada(/Pantauan santri/, 30000), 'guru berpenugasan Keamanan (identitas pengurus): menu Keamanan');
    await keBerandaLewatKembali('Keamanan guru');
    // Admin: pengawasan tidak membuka Keamanan (server menolak), ← tetap.
    await masuk('sbx_admin'); await diBeranda();
    await tautan('alhasanapps://keamanan');
    ok(await ada(/penugasan Keamanan|tidak dapat diakses|belum dapat ditampilkan/, 30000), 'admin tanpa penugasan Keamanan: ditolak server');
    await keBerandaLewatKembali('Keamanan ditolak');
  }
} catch (e) {
  ok(false, `galat: ${e.message}`);
  try { foto(`galat-${bagian}`); } catch { /* */ }
} finally {
  try { skala(1.0); gelap(false); } catch { /* */ }
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
