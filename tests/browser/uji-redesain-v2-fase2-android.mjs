import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Redesain tampilan aplikasi gelombang 2 Fase 2 — Android EMULATOR (dev client
// Debug + Metro, API/DB uji lokal lewat `adb reverse`). Bukan perangkat fisik
// dan bukan build rilis.
//
//   (DB dan API seperti uji-redesain-v2-fase2.mjs, port 8971; bagian `izin`
//    memakai DB V7, bagian `pengajian` memakai DB kelas gabungan + SEED_JSON)
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 npx expo start --dev-client --port 8964 --clear &)
//   adb reverse tcp:8971 tcp:8971; adb reverse tcp:8964 tcp:8964
//   METRO=http://127.0.0.1:8964 OUT_DIR=… node uji-redesain-v2-fase2-android.mjs izin
//   METRO=… SEED_JSON=ids.json OUT_DIR=… node uji-redesain-v2-fase2-android.mjs pengajian
//
//   izin
//     N-A  FAB K-8 di Perizinan (Material 3): 56 dp, 16 dp dari kanan, 16 dp di atas
//          bilah tab sistem, tidak menutupi baris terakhir/paginasi, hilang saat papan ketik
//     N-B  syarat tampil per peran (pengurus, admin, pembimbing, murobi, orang tua) dan FAB wali
//     N-C  ← tab Perizinan → Beranda; ← dan tombol kembali Android pada layar stack; tautan
//     N-D  Notifikasi: ⋯ → "Tandai semua dibaca"
//     N-E  mode gelap; teks 1,3× (FAB) dan 2,0× (alternatif tombol atas)
//   pengajian
//     N-F  guru: Jadwal ber-←, rincian tugas, absensi (⋯ → Perbarui daftar, pemilih status,
//          papan ketik), kirim; gelap + 2,0×
const bagian = process.argv[2];
if (!['izin', 'pengajian'].includes(bagian)) throw Error('Pemakaian: <izin|pengajian>');
const metro = process.env.METRO || '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(metro)) throw Error('Metro harus 127.0.0.1');
const ADB = process.env.ADB || `${process.env.HOME}/Library/Android/sdk/platform-tools/adb`;
const PKG = 'com.ilanmochamad.alhasanApps';
const out = process.env.OUT_DIR || '/tmp/rtv2-f2-android';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

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
/**
 * Dev client menggambar tombol mengambang "Tools" di kanan atas, tepat di atas
 * tombol ⋯ kepala layar, sehingga ketukan ⋯ membuka menu pengembang. Itu
 * artefak build Debug (tidak ada di build rilis); sakelarnya dimatikan lewat
 * menu pengembang sebelum ⋯ diuji. Data aplikasi yang dikosongkan saat masuk
 * mengembalikan sakelar, jadi dipanggil sesudah setiap masuk yang memerlukan ⋯.
 */
async function matikanTombolTools() {
  adb('shell', 'input', 'keyevent', '82');
  await tidur(1800);
  let nodes = dump();
  if (!nodes.some(n => /^Runtime version:/.test(n.text))) return false;
  adb('shell', 'input', 'swipe', '540', '2100', '540', '900', '300');
  await tidur(900);
  nodes = dump();
  const baris = nodes.find(n => n.text === 'Tools button');
  if (baris) {
    adb('shell', 'input', 'tap', String(layar()[0] - 150), String((baris.b[1] + baris.b[3]) >> 1));
    await tidur(600);
  }
  adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
  await tidur(1200);
  return Boolean(baris);
}
/** Ketuk kecocokan TERBAWAH di layar (tombol bilah aksi, bukan judul bagian bernama sama). */
async function ketukTerbawah(pola, timeout = 20000) {
  const { nodes } = await cari(pola, { timeout });
  const n = nodes.filter(x => pola.test(x.text) || pola.test(x.desc)).sort((x, y) => y.b[1] - x.b[1])[0];
  adb('shell', 'input', 'tap', String((n.b[0] + n.b[2]) >> 1), String((n.b[1] + n.b[3]) >> 1));
}
/** Tab bilah bawah sistem (label teks tab). */
async function tab(nama) {
  const nodes = dump();
  const kandidat = nodes.filter(n => (n.text === nama || n.desc === nama)).sort((x, y) => y.b[1] - x.b[1]);
  if (!kandidat.length) throw Error(`Tab ${nama} tidak ditemukan`);
  const n = kandidat[0];
  adb('shell', 'input', 'tap', String((n.b[0] + n.b[2]) >> 1), String((n.b[1] + n.b[3]) >> 1));
  await tidur(1500);
}
/** Tepi atas bilah tab sistem = tepi atas label tab terbawah dikurangi ikon (dipakai sebagai batas kasar). */
function bilahTab(nodes) {
  const label = nodes.filter(n => ['Beranda', 'Profil'].includes(n.text) || ['Beranda', 'Profil'].includes(n.desc)).sort((x, y) => y.b[1] - x.b[1])[0];
  // Wadah bilah: simpul terlebar yang memuat label tab dan menempel di dasar layar.
  const [, tinggi] = layar();
  const wadah = nodes.filter(n => label && n.b[0] <= 0 && n.b[2] >= layar()[0] && n.b[1] < label.b[1] && n.b[3] >= label.b[3] && n.b[3] >= tinggi - 200 && n.b[3] - n.b[1] < 500)
    .sort((x, y) => (x.b[3] - x.b[1]) - (y.b[3] - y.b[1]))[0];
  return { label, atas: wadah ? wadah.b[1] : label ? label.b[1] : tinggi };
}
/** Geometri FAB terhadap layar dan bilah tab (dp). `bilah` false untuk layar stack. */
async function ukurFab(nama, desc, { bilah = true } = {}) {
  await tidur(800);
  const nodes = dump();
  const fab = nodes.find(n => n.desc === desc && n.cls.includes('') && n.b[3] - n.b[1] > 0);
  if (!fab) { ok(false, `${nama}: FAB "${desc}" tampil`); return null; }
  const k = dp(), [lebar, tinggi] = layar();
  const t = (fab.b[3] - fab.b[1]) / k, l = (fab.b[2] - fab.b[0]) / k, kanan = (lebar - fab.b[2]) / k;
  ok(t >= 55.5 && t <= 62, `${nama}: tinggi FAB ${t.toFixed(0)} dp (≥ 56)`);
  ok(Math.abs(kanan - 16) <= 2, `${nama}: FAB ${kanan.toFixed(0)} dp dari tepi kanan (16)`);
  ok(l <= lebar / k / 2, `${nama}: lebar FAB ${l.toFixed(0)} dp ≤ setengah layar (${(lebar / k / 2).toFixed(0)} dp)`);
  if (bilah) {
    const b = bilahTab(nodes);
    const jarak = (b.atas - fab.b[3]) / k;
    ok(fab.b[3] <= b.atas && jarak >= 12 && jarak <= 24, `${nama}: FAB di atas bilah tab sistem, jarak ${jarak.toFixed(0)} dp (16; tepi atas bilah ${b.atas}px dari sistem)`);
  } else {
    const nav = (tinggi - fab.b[3]) / k;
    ok(nav >= 16 && nav <= 80, `${nama}: FAB ${nav.toFixed(0)} dp dari dasar layar (16 + area aman bawah)`);
  }
  ok(/Ajukan izin/.test(isi(nodes.filter(n => n.b[0] >= fab.b[0] && n.b[2] <= fab.b[2] && n.b[1] >= fab.b[1] && n.b[3] <= fab.b[3]))) || /Ajukan izin/.test(fab.desc), `${nama}: FAB berteks "Ajukan izin" (ikon + teks)`);
  return fab;
}

try {
  skala(1.0); gelap(false);
  if (bagian === 'izin') {
    console.log('\n=== N-A. FAB K-8 di Perizinan (pengurus) ===');
    await masuk('sbx_pengurus_a');
    await diBeranda();
    await tab('Izin');
    await cari(/^Kembali ke Beranda$/, { timeout: 20000 });
    await cari(/^Cari santri, NIS, atau alasan$|Cari pengajuan izin/, { timeout: 20000 });
    await tidur(2500);
    let d = dump();
    const kb = d.find(n => n.desc === 'Kembali ke Beranda'), judul = d.find(n => n.text === 'Perizinan');
    ok(Boolean(kb && judul) && kb.b[3] <= judul.b[1] + 2 && (kb.b[2] - kb.b[0]) / dp() >= 43.5 && (kb.b[3] - kb.b[1]) / dp() >= 43.5, 'Perizinan: ← "Kembali ke Beranda" ≥ 44 dp di atas judul besar');
    const cariKolom = d.find(n => n.cls.includes('EditText')), pil = d.find(n => /^Perlu tindakan/.test(n.text) || /^Perlu tindakan/.test(n.desc));
    ok(Boolean(cariKolom && pil) && cariKolom.b[3] <= pil.b[1] + 2, 'Perizinan: SearchBar di atas pil penyaring');
    let fab = await ukurFab('Perizinan pengurus', 'Ajukan izin baru');
    foto('perizinan-pengurus');
    // Papan ketik terbuka → FAB hilang; ditutup → kembali.
    await ketuk(/Cari santri, NIS, atau alasan|Cari pengajuan izin/, { kelas: 'EditText' });
    await tidur(1500);
    const papan = /mInputShown=true|isInputViewShown=true/.test(adb('shell', 'dumpsys', 'input_method'));
    ok(papan && !dump().some(n => n.desc === 'Ajukan izin baru'), `Papan ketik terbuka (${papan}): FAB disembunyikan`);
    foto('perizinan-papan-ketik');
    tutupKeyboard();
    await tidur(1200);
    ok(dump().some(n => n.desc === 'Ajukan izin baru'), 'Papan ketik ditutup: FAB kembali');
    // Ujung daftar: paginasi/baris terakhir di atas FAB.
    await ketuk(/^Semua$/);
    await tidur(2500);
    for (let i = 0; i < 14; i++) { geser(1800, 500); await tidur(350); }
    await tidur(800);
    d = dump();
    fab = d.find(n => n.desc === 'Ajukan izin baru');
    const akhir = d.filter(n => /^(Berikutnya|Sebelumnya)$/.test(n.text) || /^(Berikutnya|Sebelumnya)$/.test(n.desc) || /^Pengajuan \d+ untuk /.test(n.desc)).sort((x, y) => y.b[3] - x.b[3])[0];
    ok(Boolean(fab && akhir) && akhir.b[3] <= fab.b[1], `Ujung daftar: elemen terakhir (${akhir ? (akhir.text || akhir.desc).slice(0, 24) : '—'}, bawah ${akhir?.b[3]}) di atas FAB (atas ${fab?.b[1]})`);
    foto('perizinan-ujung-daftar');
    for (let i = 0; i < 14; i++) { geser(600, 1800); await tidur(250); }

    console.log('\n=== N-C. ← dan tombol kembali ===');
    await ketuk(/^Ajukan izin baru$/);
    await cari(/Lanjut isi data izin/, { timeout: 20000 });
    d = dump();
    ok(d.some(n => n.desc === 'Kembali ke halaman sebelumnya') && d.some(n => n.text === 'Ajukan izin'), 'FAB membuka formulir; kepala stack ber-← (label khusus)');
    foto('buat-izin');
    await ketuk(/^Kembali ke halaman sebelumnya$/);
    ok(await ada(/^Kembali ke Beranda$/, 10000), 'Buat izin: ← kembali ke Perizinan');
    await ketuk(/^Ajukan izin baru$/);
    await cari(/Lanjut isi data izin/, { timeout: 20000 });
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    ok(await ada(/^Kembali ke Beranda$/, 10000), 'Buat izin: tombol kembali Android kembali ke Perizinan');
    // Rincian izin dari daftar.
    await ketuk(/^Pengajuan \d+ untuk /);
    await cari(/Riwayat perubahan|Rincian izin/, { timeout: 20000 });
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Rincian izin'), 'Rincian izin: kepala stack ber-←');
    foto('rincian-izin');
    await ketuk(/^Kembali$/);
    ok(await ada(/^Kembali ke Beranda$/, 10000), 'Rincian izin: ← kembali ke Perizinan');
    await ketuk(/^Kembali ke Beranda$/);
    await diBeranda(15000);
    ok(!(await ada(/^Kembali ke Beranda$/, 1500)), 'Perizinan: ← membuka tab Beranda');
    // Tautan saat aplikasi berjalan.
    adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', 'alhasanapps://notifikasi', PKG);
    ok(await ada(/Sudah dibaca/, 20000), 'Tautan alhasanapps://notifikasi membuka Notifikasi');

    console.log('\n=== N-D. Notifikasi ===');
    console.log(`  (tombol Tools dev client dimatikan: ${await matikanTombolTools()})`);
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Notifikasi') && d.some(n => n.desc === 'Tindakan lain'), 'Notifikasi: kepala stack ← · judul · ⋯');
    foto('notifikasi');
    await ketuk(/^Tindakan lain$/);
    ok(await ada(/^Perangkat & push/, 8000), '⋯ membuka lembar bawah berisi tindakan sekunder');
    foto('notifikasi-menu');
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    await tidur(800);
    await ketuk(/^Kembali$/);
    await tidur(1500);
    ok(!(await ada(/Sudah dibaca/, 1500)), 'Notifikasi: ← membawa ke layar aplikasi');

    console.log('\n=== N-B. Syarat tampil FAB per peran ===');
    for (const [user, label, harap] of [['sbx_admin', 'Admin', true], ['sbx_v7_pembimbing_satu', 'Pembimbing', true], ['sbx_murobi_a', 'Murobi', false], ['sbx_ortu_a', 'Orang tua', false]]) {
      await masuk(user);
      await diBeranda();
      await tab('Izin');
      await cari(/^Kembali ke Beranda$/, { timeout: 20000 });
      await tidur(3500);
      const ada1 = dump().some(n => n.desc === 'Ajukan izin baru');
      ok(ada1 === harap, `${label}: FAB ${harap ? 'tampil' : 'tidak tampil'} (sama dengan capability server di uji web)`);
      foto(`perizinan-${user}`);
    }
    await masuk('sbx_v7_wali_a');
    await diBeranda();
    await tab('Izin');
    await ketuk(/^Buka izin orang tua melalui pembimbing$/, { timeout: 20000 });
    await cari(/Izin anak melalui pembimbing/, { timeout: 20000 });
    await tidur(2000);
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Izin melalui pembimbing'), 'Daftar izin wali: kepala stack ber-←');
    await ukurFab('Izin wali (orang tua)', 'Ajukan izin baru', { bilah: false });
    foto('izin-wali-daftar');
    await ketuk(/^Ajukan izin baru$/);
    await cari(/Rentang izin/, { timeout: 20000 });
    ok(dump().some(n => n.desc === 'Kembali'), 'Ajukan izin anak: ← tampil');
    foto('izin-wali-buat');
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    ok(await ada(/Izin anak melalui pembimbing/, 10000), 'Ajukan izin anak: tombol kembali Android kembali ke daftar');

    console.log('\n=== N-E. Mode gelap dan teks sistem 1,3× / 2,0× (pengurus) ===');
    for (const [nama, g, s] of [['gelap', true, 1.0], ['teks-1.3', false, 1.3], ['teks-2.0', false, 2.0], ['gelap-teks-2.0', true, 2.0]]) {
      gelap(g); skala(s);
      await masuk('sbx_pengurus_a');
      await diBeranda(60000);
      await tab('Izin');
      await cari(/^Kembali ke Beranda$/, { timeout: 20000 });
      await tidur(3000);
      const n = dump();
      const [lebar] = layar();
      const alternatif = 70 + 84 * s > lebar / dp() / 2;
      const fabAda = n.some(x => x.desc === 'Ajukan izin baru' && (lebar - x.b[2]) / dp() < 30 && (x.b[2] - x.b[0]) < lebar * 0.6);
      if (alternatif) {
        const atas = n.find(x => x.desc === 'Ajukan izin baru' || x.text === 'Ajukan izin baru');
        const kolom = n.find(x => x.cls.includes('EditText'));
        ok(Boolean(atas) && !fabAda && (!kolom || atas.b[3] <= kolom.b[1] + 4), `Perizinan ${nama}: FAB akan melewati setengah layar → alternatif tombol lebar penuh di atas kotak cari`);
      } else {
        ok(fabAda, `Perizinan ${nama}: FAB tampil`);
        await ukurFab(`Perizinan ${nama}`, 'Ajukan izin baru');
      }
      ok(/Perizinan/.test(isi(n)) && n.some(x => x.desc === 'Kembali ke Beranda'), `Perizinan ${nama}: judul dan ← tampil`);
      foto(`perizinan-${nama}`);
      geser(1700, 700); await tidur(700);
      foto(`perizinan-${nama}-gulir`);
      geser(700, 1700); await tidur(500);
      await ketuk(/^Pengajuan \d+ untuk /, { timeout: 15000 }).catch(async () => { await gulirKe(/^Pengajuan \d+ untuk /); await ketuk(/^Pengajuan \d+ untuk /); });
      await cari(/^Rincian izin$/, { timeout: 20000 });
      await tidur(800);
      ok(dump().some(x => x.desc === 'Kembali'), `Rincian izin ${nama}: ← tampil`);
      foto(`rincian-izin-${nama}`);
    }
    gelap(false); skala(1.0);
  }

  if (bagian === 'pengajian') {
    const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
    console.log('\n=== N-F. Guru: Jadwal, rincian tugas, absensi ===');
    await masuk('sbx_guru_biasa');
    await diBeranda();
    await tab('Jadwal');
    await cari(/^Kembali ke Beranda$/, { timeout: 20000 });
    await cari(new RegExp(`^Nahwu ${ids.sfx}, `), { timeout: 30000 });
    let d = dump();
    const kb = d.find(n => n.desc === 'Kembali ke Beranda'), judul = d.find(n => n.text === 'Jadwal');
    ok(Boolean(kb && judul) && kb.b[3] <= judul.b[1] + 2 && /Sesi mengajar pekan ini/.test(isi(d)), 'Jadwal: ← di atas judul besar + subjudul');
    ok(/Belum diabsen/.test(isi(d)) && /Per pekan/.test(isi(d)), 'Jadwal: pil tampilan dan pil status sesi');
    foto('jadwal');
    await ketuk(new RegExp(`^Nahwu ${ids.sfx}, `));
    await cari(/^Buka pertemuan$/, { timeout: 20000 });
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Rincian tugas') && /Kelas gabungan/.test(isi(d)), 'Rincian tugas: kepala stack ber-←, kelas gabungan');
    foto('rincian-tugas');
    adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
    ok(await ada(/^Kembali ke Beranda$/, 10000), 'Rincian tugas: tombol kembali Android kembali ke Jadwal');
    await ketuk(new RegExp(`^Nahwu ${ids.sfx}, `));
    await ketukTerbawah(/^Buka pertemuan$/);
    await cari(/Santri · 5 orang/, { timeout: 30000 });
    await tidur(1500);
    d = dump();
    ok(d.some(n => n.desc === 'Kembali') && d.some(n => n.text === 'Absensi pertemuan') && d.some(n => n.desc === 'Tindakan lain'), 'Absensi: kepala stack ← · judul · ⋯');
    ok(!d.some(n => n.text === 'Perbarui daftar' && n.cls.includes('Button')) && d.some(n => n.desc === 'Hadir: 5'), 'Absensi: "Perbarui daftar" bukan tombol langsung; ringkasan mini tampil');
    foto('absensi');
    console.log(`  (tombol Tools dev client dimatikan: ${await matikanTombolTools()})`);
    await ketuk(/^Tindakan lain$/);
    await ketuk(/^Perbarui daftar$/, { timeout: 8000 });
    ok(await ada(/Daftar santri sudah sesuai dengan penempatan kelas terbaru\./, 20000), '⋯ → "Perbarui daftar": pesan hasil tampil');
    await gulirKe(/Catatan santri \(opsional\)/, 10);
    d = dump();
    // Tombol status santri (ringkas, lima sama lebar) — bukan pemilih kehadiran guru di atasnya.
    const catatan = d.find(n => /Catatan santri \(opsional\)/.test(n.text));
    const sakit = d.filter(n => n.desc === 'Sakit' && n.b[3] <= catatan.b[1]).sort((x, y) => y.b[1] - x.b[1])[0];
    ok(Boolean(sakit) && (sakit.b[3] - sakit.b[1]) / dp() >= 43.5 && (sakit.b[2] - sakit.b[0]) / dp() >= 43.5, `Absensi: tombol status santri ${sakit ? ((sakit.b[2] - sakit.b[0]) / dp()).toFixed(0) : '—'}×${sakit ? ((sakit.b[3] - sakit.b[1]) / dp()).toFixed(0) : '—'} dp ≥ 44`);
    adb('shell', 'input', 'tap', String((sakit.b[0] + sakit.b[2]) >> 1), String((sakit.b[1] + sakit.b[3]) >> 1));
    await tidur(800);
    foto('absensi-daftar');
    for (let i = 0; i < 6; i++) { geser(600, 1800); await tidur(250); }
    ok(await ada(/^Sakit: 1$/, 5000), 'Absensi: ringkasan mini mengikuti draf (Sakit: 1)');
    ok(dump().some(n => n.desc === 'Kembali'), 'Absensi: ← tetap terlihat (kepala di luar area gulir)');
    await ketuk(/^Kirim absensi$/);
    await ketuk(/^SIMPAN$|^Simpan$/, { timeout: 10000 });
    await gulirKe(/Absensi berhasil disimpan/, 16);
    ok(await ada(/Absensi berhasil disimpan/, 5000), 'Kirim absensi: panel hasil tampil');
    foto('absensi-tersimpan');

    console.log('\n=== N-E. Mode gelap dan teks 2,0× (guru) ===');
    for (const [nama, g, s] of [['gelap', true, 1.0], ['gelap-teks-2.0', true, 2.0]]) {
      gelap(g); skala(s);
      await masuk('sbx_guru_biasa');
      await diBeranda(60000);
      await tab('Jadwal');
      await cari(/^Kembali ke Beranda$/, { timeout: 20000 });
      await tidur(3000);
      foto(`jadwal-${nama}`);
      await gulirKe(new RegExp(`^Nahwu ${ids.sfx}, `));
      await ketuk(new RegExp(`^Nahwu ${ids.sfx}, `));
      await cari(/^Rincian tugas$/, { timeout: 20000 });
      await tidur(800);
      foto(`rincian-tugas-${nama}`);
      await gulirKe(/^Buka absensi tersimpan$/, 6);
      await ketuk(/^Buka absensi tersimpan$/);
      await cari(/^Absensi pertemuan$/, { timeout: 20000 });
      await tidur(1500);
      const n = dump();
      ok(n.some(x => x.desc === 'Kembali') && /Santri · 5 orang|Hadir/.test(isi(n)), `Absensi ${nama}: ← dan isi tampil`);
      foto(`absensi-${nama}`);
      geser(1700, 600); await tidur(700);
      foto(`absensi-${nama}-gulir`);
    }
    gelap(false); skala(1.0);
  }
} catch (e) {
  gagal++;
  console.log(`[gagal] Pengecualian: ${e.message.split('\n')[0]}`);
  try { foto('galat'); console.log('  layar: ' + isi(dump()).slice(0, 500)); } catch { /* emulator tidak menjawab */ }
} finally {
  try { skala(1.0); gelap(false); } catch { /* abaikan */ }
}
console.log(`\nHasil (${bagian}): ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
