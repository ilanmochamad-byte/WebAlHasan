import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Redesain tampilan aplikasi gelombang 2 Fase 1 — Android EMULATOR (dev client
// Debug + Metro, API/DB uji lokal lewat `adb reverse`). Bukan perangkat fisik
// dan bukan build rilis.
//
//   (DB dan API seperti uji-redesain-v2-fase1.mjs, port 8971)
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 npx expo start --dev-client --port 8974 --clear &)
//   adb reverse tcp:8971 tcp:8971; adb reverse tcp:8974 tcp:8974
//   METRO=http://127.0.0.1:8974 OUT_DIR=… node uji-redesain-v2-fase1-android.mjs
//
//   N-A  tujuh peran: kepala Beranda (logo + lonceng, tanpa ←), kartu identitas, Profil ber-←, ← ke Beranda
//   N-B  ← pada Edit profil dan Ganti password; tombol kembali Android; tautan alhasanapps:// saat aplikasi berjalan
//   N-C  mode gelap, teks sistem 1,3× dan 2,0× (tangkapan + teks kunci tetap ada)
//   N-D  Masuk dan Ganti password wajib tanpa ←
const metro = process.env.METRO || '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(metro)) throw Error('Metro harus 127.0.0.1');
const ADB = process.env.ADB || `${process.env.HOME}/Library/Android/sdk/platform-tools/adb`;
const PKG = 'com.ilanmochamad.alhasanApps';
const out = process.env.OUT_DIR || '/tmp/rtv2-android';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const HANYA = (process.env.HANYA || 'A,B,C,D').split(',');

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
  adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', `alhasanapps://expo-development-client/?url=${encodeURIComponent(metro)}`);
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
const diBeranda = (timeout = 40000) => cari(/Assalamu.alaikum,/, { timeout });
async function bukaProfil() {
  for (let i = 0; i < 4; i++) {
    await ketuk(/Buka profil/);
    if (await ada(/Kembali ke Beranda/, 6000)) return;
  }
  throw Error('Profil tidak terbuka');
}
/** Gulir sampai pola terlihat (maks. 12 kali). */
async function gulirKe(pola) {
  for (let i = 0; i < 12; i++) {
    if (await ada(pola, 1200)) return true;
    geser(1700, 900);
    await tidur(500);
  }
  return false;
}

try {
  skala(1.0); gelap(false);
  const PERAN = [
    ['sbx_guru_biasa', 'Guru'], ['sbx_pengurus_biasa', 'Pengurus (hanya perizinan)'], ['sbx_ortu_a', 'Orang tua'],
    ['sbx_v6_bsa', 'Petugas BSA'], ['sbx_v5_petugas', 'Petugas kesehatan'], ['sbx_pengurus_b', 'Keamanan'], ['sbx_admin', 'Admin'],
  ];
  if (HANYA.includes('A')) {
    console.log('\n=== N-A. Tujuh peran: Beranda dan Profil ===');
    for (const [user, label] of PERAN) {
      await masuk(user);
      if (user === PERAN[0][0]) {
        // N-D sebagian: layar Masuk sebelum sesi ada.
      }
      const { nodes } = await diBeranda();
      await tidur(2500);
      const b = dump();
      const logo = b.find(n => n.desc === 'Alhasan apps');
      const lonceng = b.find(n => /^Notifikasi/.test(n.desc));
      const kartu = b.find(n => /Buka profil/.test(n.desc));
      ok(Boolean(logo && lonceng && kartu) && logo.b[1] < kartu.b[1] && lonceng.b[0] > logo.b[2] && lonceng.b[3] <= kartu.b[1] + 4,
        `${label}: kepala Beranda — logo "Alhasan apps" kiri, lonceng kanan, di atas kartu identitas`);
      ok(!b.some(n => /^Kembali/.test(n.desc)), `${label}: Beranda tanpa tombol ←`);
      const t = isi(b);
      ok(/Ringkasan hari ini/.test(t) && /Menu/.test(t), `${label}: ringkasan dan menu tampil`);
      foto(`beranda-${user}`);
      await bukaProfil();
      await tidur(1200);
      const p = dump();
      const kb = p.find(n => n.desc === 'Kembali ke Beranda');
      const lebar = kb ? kb.b[2] - kb.b[0] : 0, tinggi = kb ? kb.b[3] - kb.b[1] : 0;
      const dp = Number((adb('shell', 'wm', 'density').match(/(\d+)\s*$/m) || [0, 420])[1]) / 160;
      ok(Boolean(kb) && lebar / dp >= 43.5 && tinggi / dp >= 43.5 && /Profil/.test(isi(p)) && isi(p).includes('@' + user), `${label}: Profil ber-← "Kembali ke Beranda" (${(lebar / dp).toFixed(0)}×${(tinggi / dp).toFixed(0)} dp), judul dan username`);
      foto(`profil-${user}`);
      await ketuk(/^Kembali ke Beranda$/);
      await diBeranda(15000);
      ok(!(await ada(/^Kembali ke Beranda$/, 1500)), `${label}: ← di Profil membuka tab Beranda`);
      void nodes;
    }
  }

  if (HANYA.includes('B')) {
    console.log('\n=== N-B. ← pada layar stack, tombol kembali Android, tautan ===');
    await masuk('sbx_pengurus_biasa');
    await diBeranda();
    await bukaProfil();
    await ketuk(/^Edit profil$/);
    await cari(/JPEG, PNG, atau WebP/, { timeout: 20000 });
    let d = dump();
    const kb = d.find(n => n.desc === 'Kembali'), judul = d.find(n => n.text === 'Edit profil');
    const lebarLayar = Number((adb('shell', 'wm', 'size').match(/(\d+)x\d+\s*$/m) || [0, 1080])[1]);
    ok(Boolean(kb && judul) && kb.b[2] <= judul.b[0] + 2 && Math.abs((judul.b[0] + judul.b[2]) / 2 - lebarLayar / 2) <= 6,
      `Edit profil: kepala stack — ← kiri, judul di tengah (pusat ${judul ? (judul.b[0] + judul.b[2]) / 2 : '—'} dari ${lebarLayar / 2})`);
    foto('edit-profil');
    // Judul kelompok dapat terlihat lebih dulu daripada barisnya; gulir sampai barisnya sendiri tampil.
    ok(await gulirKe(/Diatur admin/) && await gulirKe(/@sbx_pengurus_biasa, hanya dapat diubah admin/), 'Edit profil: isian terkunci admin tampil');
    ok(await ada(/^Simpan perubahan$/, 2000), 'Edit profil: tombol "Simpan perubahan" tetap terlihat saat digulir');
    await ketuk(/^Kembali$/);
    ok(await ada(/Kembali ke Beranda/, 10000), 'Edit profil: ← kembali ke Profil');
    await ketuk(/^Edit profil$/);
    await cari(/JPEG, PNG, atau WebP/, { timeout: 20000 });
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    ok(await ada(/Kembali ke Beranda/, 10000), 'Edit profil: tombol kembali Android kembali ke Profil');
    await gulirKe(/^Ganti password/);
    await ketuk(/^Ganti password/);
    await cari(/Ulangi password baru/, { timeout: 20000 });
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Ganti password'), 'Ganti password: kepala stack ber-←');
    foto('ganti-password');
    await ketuk(/^Kembali$/);
    ok(await ada(/Perangkat & push|Kembali ke Beranda/, 10000), 'Ganti password: ← kembali ke Profil');
    // Tautan saat aplikasi berjalan (ada riwayat): layar terbuka dan ← kembali.
    adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', 'alhasanapps://akun/ganti-password', PKG);
    ok(await ada(/Ulangi password baru/, 20000), 'Tautan alhasanapps://akun/ganti-password membuka layar Ganti password');
    await ketuk(/^Kembali$/);
    await tidur(1500);
    ok(!(await ada(/Ulangi password baru/, 1500)) && await ada(/Assalamu.alaikum,|Perangkat & push|Kembali ke Beranda/, 8000), '← dari layar yang dibuka lewat tautan membawa ke layar aplikasi (bukan diam/menutup)');
    foto('sesudah-tautan');
  }

  if (HANYA.includes('C')) {
    console.log('\n=== N-C. Mode gelap dan teks sistem 1,3× / 2,0× (sbx_admin) ===');
    for (const [nama, g, s] of [['gelap', true, 1.0], ['teks-1.3', false, 1.3], ['teks-2.0', false, 2.0], ['gelap-teks-2.0', true, 2.0]].filter(v => !process.env.SKALA || process.env.SKALA.split(',').includes(v[0]))) {
      gelap(g); skala(s);
      await masuk('sbx_admin');
      await diBeranda(60000);
      await tidur(3000);
      const b = dump();
      const t = isi(b);
      ok(b.some(n => n.desc === 'Alhasan apps') && /SBX Administrator/.test(t) && /Ringkasan hari ini/.test(t), `Beranda ${nama}: logo, nama, ringkasan tampil`);
      const logo = b.find(n => n.desc === 'Alhasan apps');
      const dp = Number((adb('shell', 'wm', 'density').match(/(\d+)\s*$/m) || [0, 420])[1]) / 160;
      ok(Boolean(logo) && Math.abs((logo.b[3] - logo.b[1]) / dp - 28) <= 1.5, `Beranda ${nama}: logo tetap ±28 dp (tidak ikut skala teks): ${logo ? ((logo.b[3] - logo.b[1]) / dp).toFixed(1) : '—'}`);
      foto(`beranda-${nama}`);
      geser(1700, 700); await tidur(700);
      foto(`beranda-${nama}-gulir`);
      const ubin = dump().filter(n => ['Jadwal', 'Absensi', 'Perizinan'].includes(n.desc) || ['Jadwal', 'Absensi', 'Perizinan'].includes(n.text));
      ok(new Set(ubin.map(n => n.b[1])).size >= 1 && ubin.length >= 3, `Beranda ${nama}: ubin Jadwal/Absensi/Perizinan tampil (kisi 3 kolom diperiksa pada tangkapan)`);
      geser(700, 1700); geser(700, 1700); await tidur(700);
      await bukaProfil();
      await tidur(1200);
      foto(`profil-${nama}`);
      ok(await ada(/^Edit profil$/, 4000) || await gulirKe(/^Edit profil$/), `Profil ${nama}: tombol Edit profil terjangkau`);
      await ketuk(/^Edit profil$/);
      await cari(/JPEG, PNG, atau WebP/, { timeout: 20000 });
      await tidur(800);
      foto(`edit-profil-${nama}`);
      ok(dump().some(n => n.desc === 'Kembali'), `Edit profil ${nama}: ← tampil`);
      await ketuk(/^Kembali$/);
      await cari(/Kembali ke Beranda/, { timeout: 10000 });
      await gulirKe(/^Ganti password/);
      await ketuk(/^Ganti password/);
      await cari(/Password saat ini/, { timeout: 20000 });
      await tidur(800);
      foto(`ganti-password-${nama}`);
      ok(dump().some(n => n.desc === 'Kembali'), `Ganti password ${nama}: ← tampil`);
    }
    gelap(false); skala(1.0);
  }

  if (HANYA.includes('D')) {
    console.log('\n=== N-D. Masuk dan Ganti password wajib ===');
    adb('shell', 'pm', 'clear', PKG);
    await bukaApp();
    await cari(/Masukkan username/, { timeout: 120000 });
    await tidur(1500);
    let d = dump();
    ok(!d.some(n => /^Kembali/.test(n.desc)) && d.some(n => n.desc === 'Alhasan Apps') && !d.some(n => n.desc === 'Alhasan apps'), 'Masuk: tanpa ←, logo lama, tanpa logo teks K-9');
    foto('masuk');
    await masuk('sbx_murobi_c', 'Sementara#2026A');
    await cari(/Buat password baru/, { timeout: 40000 });
    await tidur(1200);
    d = dump();
    ok(!d.some(n => /^Kembali/.test(n.desc)) && /Ganti password sementara/.test(isi(d)), 'Ganti password wajib: tanpa ←, judul dan penjelas tampil');
    foto('ganti-password-wajib');
    ok(await gulirKe(/^Keluar$/), 'Ganti password wajib: tombol Keluar terjangkau');
  }
} catch (e) {
  gagal++;
  console.log(`[gagal] Pengecualian: ${e.message.split('\n')[0]}`);
  try { foto('galat'); console.log('  layar: ' + isi(dump()).slice(0, 400)); } catch { /* emulator tidak menjawab */ }
} finally {
  try { skala(1.0); gelap(false); } catch { /* abaikan */ }
}
console.log(`\nHasil: ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
