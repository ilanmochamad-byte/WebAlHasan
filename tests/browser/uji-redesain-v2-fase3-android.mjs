import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Redesain tampilan aplikasi gelombang 2 Fase 3 (Keuangan/BSA) — Android EMULATOR
// (dev client Debug + Metro, API/DB uji lokal lewat `adb reverse`). Bukan perangkat
// fisik dan bukan build rilis. Pembantu adb/uiautomator sama dengan uji Fase 2.
//
//   (DB seperti suite `fase3` perintah-uji.sh; API 127.0.0.1:8971)
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1 npx expo start --dev-client --port 8964 --clear &)
//   adb reverse tcp:8971 tcp:8971; adb reverse tcp:8964 tcp:8964
//   DB_NAME=test_*_test METRO=http://127.0.0.1:8964 OUT_DIR=… node uji-redesain-v2-fase3-android.mjs
//
//   A-1  petugas: menu (ubin), rincian santri (strip, tab), ← ≥ 44 dp; setoran ketukan ganda →
//        satu transaksi; tombol kembali Android; tangkapan layar Keuangan terlindungi (FLAG_SECURE)
//   A-2  tautan alhasanapps://keuangan/tagihan/{id} → ← membuka Beranda
//   A-3  penugasan dicabut saat formulir terbuka → kirim ditolak, data ditutup
//   A-4  admin hanya baca; orang tua per anak, tanpa "Transfer menunggu"; relasi dicabut → ditutup
//   A-5  mode gelap dan teks 2,0×: kepala, strip, ← tetap ada
const db = process.env.DB_NAME || '';
if (!/^test_.*_test$/.test(db)) throw Error('DB test_*_test');
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const metro = process.env.METRO || '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(metro)) throw Error('Metro harus 127.0.0.1');
const ADB = process.env.ADB || `${process.env.HOME}/Library/Android/sdk/platform-tools/adb`;
const PKG = 'com.ilanmochamad.alhasanApps';
const out = process.env.OUT_DIR || '/tmp/rtv2-f3-android';
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
/** Ukuran tombol ← "Kembali" dalam dp. */
async function ukurKembali(nama) {
  const { n } = await cari(/^Kembali$/);
  const k = dp(), w = (n.b[2] - n.b[0]) / k, h = (n.b[3] - n.b[1]) / k;
  // Batas piksel dibulatkan perangkat (mis. 115 px / 2,625 = 43,8 dp untuk 44 dp di kode).
  ok(Math.round(w) >= 44 && Math.round(h) >= 44, `${nama}: ← "Kembali" ${w.toFixed(1)}×${h.toFixed(1)} dp (≈ 44)`);
}
/** Tangkapan layar Keuangan terlindungi FLAG_SECURE: PNG hitam polos (kecil) dibanding Beranda. */
function ukuranTangkapan(nama) {
  const png = execFileSync(ADB, ['exec-out', 'screencap', '-p'], { maxBuffer: 64 << 20 });
  fs.writeFileSync(path.join(out, `android-${nama}.png`), png);
  return png.length;
}
/** Buka tautan saat aplikasi berjalan; URL dikutip karena `adb shell` menafsirkan `&`. */
const tautan = url => adb('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', `'${url}'`, PKG);
/** Tekan ← sampai Beranda (layar pertama tanpa riwayat stack di atasnya). */
async function keBeranda() {
  for (let i = 0; i < 8 && !(await ada(/Assalamu.alaikum,/, 1500)); i++) { if (await ada(/^Kembali$/, 1500)) await ketuk(/^Kembali$/); else adb('shell', 'input', 'keyevent', 'KEYCODE_BACK'); await tidur(1200); }
}
const sid = nis => Number(sql(`SELECT id FROM santri WHERE nis='${nis}'`));
const sSmp = sid('V6S0001');
const tugas = sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id = b.pengurus_id WHERE u.username = 'sbx_v6_bsa_b' ORDER BY b.id LIMIT 1");
const relasi = sql(`SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_dua' AND sw.santri_id = ${sSmp} AND sw.archived_at IS NULL LIMIT 1`);
const T = Number(sql(`SELECT id FROM bsa_tagihan WHERE santri_id=${sSmp} AND status='Terbit' ORDER BY id DESC LIMIT 1`));

try {
  skala(1); gelap(false);
  console.log('\n=== A-1. Petugas ===');
  await masuk('sbx_v6_bsa_b');
  await diBeranda();
  const besarBeranda = ukuranTangkapan('a1-beranda-kontrol');
  await gulirKe(/^Keuangan$/);
  await ketuk(/^Keuangan$/);
  await cari(/^Santri, saldo, dan tagihan$/);
  let n = dump();
  ok(['Santri, saldo, dan tagihan', 'Verifikasi transfer', 'Pembayaran tagihan', 'Mutasi tabungan', 'Tunggakan', 'Terbitkan tagihan bulanan'].every(u => n.some(x => x.desc === u || x.text === u)),
    'Menu petugas: ubin Keuangan tampil (layar pertama)');
  await ukurKembali('Menu Keuangan');
  const besarKeuangan = ukuranTangkapan('a1-menu-keuangan-terlindungi');
  ok(besarKeuangan < besarBeranda / 4, `Tangkapan layar Keuangan terlindungi: ${besarKeuangan} B (Beranda ${besarBeranda} B)`);
  console.log(`  (tombol Tools dev client dimatikan: ${await matikanTombolTools()})`);
  await ketuk(/^Santri, saldo, dan tagihan$/);
  await ketuk(/^Cari nama atau NIS$/, { kelas: 'EditText' });
  ketik('V6S0001');
  await ketuk(/^Cari$/);
  await ketuk(/^Buka keuangan SBX V6 Santri SMP$/);
  await cari(/Saldo tabungan/);
  n = dump();
  ok(['Saldo tabungan', 'Sisa tagihan', 'Transfer menunggu'].every(l => isi(n).includes(l)), 'Rincian santri: strip Saldo tabungan · Sisa tagihan · Transfer menunggu');
  ok(n.filter(x => /^(Ringkasan|Tagihan|Tabungan)$/.test(x.text)).length >= 3, 'Rincian santri: tab Ringkasan / Tagihan / Tabungan');
  await ukurKembali('Rincian santri');
  const sebelum = Number(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp}`));
  await ketuk(/^Setoran$/);
  await cari(/Saldo saat ini/);
  await ketuk(/^Nominal \(rupiah\)$/, { kelas: 'EditText' });
  ketik('25000');
  tutupKeyboard();
  await tidur(600);
  const { n: catat } = await cari(/^Catat setoran$/);
  const cx = String((catat.b[0] + catat.b[2]) >> 1), cy = String((catat.b[1] + catat.b[3]) >> 1);
  adb('shell', 'input', 'tap', cx, cy); adb('shell', 'input', 'tap', cx, cy);
  await cari(/Saldo kini/, { timeout: 30000 });
  ok(Number(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=${sSmp}`)) === sebelum + 1, 'Ketukan ganda "Catat setoran" → tepat satu transaksi baru');
  ok(await ada(/Setoran tercatat/), 'Panel hasil "Setoran tercatat" + bukti setoran');
  adb('shell', 'input', 'keyevent', 'KEYCODE_BACK');
  await tidur(1500);
  ok(await ada(/Riwayat tabungan|Tagihan bulanan/, 8000), 'Tombol kembali Android: formulir → rincian santri');
  await ketuk(/^Kembali$/);
  await tidur(1200);
  ok(await ada(/^Cari nama atau NIS$/, 8000), '← rincian santri → daftar santri (riwayat)');

  console.log('\n=== A-2. Tautan tanpa riwayat ===');
  await keBeranda();
  tautan(`alhasanapps://keuangan/tagihan/${T}`);
  await cari(/Rincian biaya/, { timeout: 30000 });
  ok(true, `alhasanapps://keuangan/tagihan/${T} membuka rincian tagihan (invoice)`);
  await ukurKembali('Rincian tagihan (tautan)');
  await ketuk(/^Kembali$/);
  await tidur(1500);
  ok(await ada(/Assalamu.alaikum,/, 5000), '← dari tautan membuka Beranda');

  console.log('\n=== A-3. Penugasan dicabut saat formulir terbuka ===');
  tautan(`alhasanapps://keuangan/transaksi?santri_id=${sSmp}&mode=setoran`);
  await cari(/Saldo saat ini/, { timeout: 30000 });
  await ketuk(/^Nominal \(rupiah\)$/, { kelas: 'EditText' });
  ketik('5000');
  tutupKeyboard();
  const jumlah = sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi`);
  sql(`UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = ${tugas}`);
  try {
    await ketuk(/^Catat setoran$/);
    await cari(/Informasi tidak dapat diakses/, { timeout: 20000 });
    n = dump();
    ok(sql(`SELECT COUNT(*) FROM bsa_tabungan_transaksi`) === jumlah && !isi(n).includes('SBX V6 Santri SMP') && !n.some(x => x.cls.includes('EditText')),
      'Ditolak 403: tanpa mutasi, nama dan kolom isian ditutup, ← tetap ada');
    ok(n.some(x => x.desc === 'Kembali' || x.text === 'Kembali'), 'Keadaan ditolak tetap ber-← (K-10)');
  } finally {
    sql(`UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = ${tugas}`);
  }

  console.log('\n=== A-4. Admin dan orang tua ===');
  await masuk('sbx_admin');
  await diBeranda();
  await gulirKe(/^Keuangan$/);
  await ketuk(/^Keuangan$/);
  await cari(/Admin — hanya membaca/);
  ok(!(await ada(/^Terbitkan tagihan bulanan$/, 1500)), 'Admin: menu hanya baca, tanpa ubin penerbitan');
  tautan(`alhasanapps://keuangan/santri/${sSmp}`);
  await cari(/Saldo tabungan/, { timeout: 30000 });
  // "Setoran" juga judul baris mutasi; yang diperiksa tombol yang hanya ada untuk petugas.
  ok(!(await ada(/^Penarikan\/belanja$|^Terbitkan tagihan$/, 1500)), 'Admin: rincian santri tanpa tombol mutasi (Penarikan/belanja, Terbitkan tagihan)');

  await masuk('sbx_v6_wali_dua');
  await diBeranda();
  await gulirKe(/^Keuangan anak$/);
  await ketuk(/^Keuangan anak$/);
  await cari(/Riwayat dan laporan|Saldo tabungan/);
  n = dump();
  ok(n.filter(x => /^Buka keuangan SBX V6 Santri/.test(x.desc)).length === 2, 'Orang tua: dua kartu anak (Buka keuangan …)');
  console.log(`  (tombol Tools dev client dimatikan: ${await matikanTombolTools()})`);
  await ketuk(/^Buka keuangan SBX V6 Santri SMP$/);
  await cari(/Saldo tabungan/);
  ok(!isi(dump()).includes('Transfer menunggu'), 'Rincian anak: strip tanpa "Transfer menunggu"');
  sql(`UPDATE santri_wali SET archived_at = NOW() WHERE id = ${relasi}`);
  try {
    await ketuk(/^Muat ulang$/);
    await cari(/Data belum dapat ditampilkan/, { timeout: 20000 });
    ok(!isi(dump()).includes('SBX V6 Santri SMP'), 'Relasi wali dicabut → muat ulang ditolak, nama anak hilang');
  } finally {
    sql(`UPDATE santri_wali SET archived_at = NULL WHERE id = ${relasi}`);
  }

  console.log('\n=== A-5. Mode gelap dan teks 2,0× ===');
  gelap(true); skala(2.0);
  await masuk('sbx_v6_bsa_b');
  await diBeranda(60000);
  tautan(`alhasanapps://keuangan/santri/${sSmp}`);
  await cari(/Saldo tabungan/, { timeout: 40000 });
  n = dump();
  ok(['Saldo tabungan', 'Sisa tagihan', 'Transfer menunggu', 'Keuangan santri'].every(l => isi(n).includes(l)), 'Gelap + 2,0×: kepala, strip, dan judul tampil');
  await ukurKembali('Gelap + 2,0× rincian santri');
  tautan(`alhasanapps://keuangan/tagihan/${T}`);
  await cari(/Rincian biaya/, { timeout: 30000 });
  // Teks > 1,35×: tombol tindakan berada di akhir isi bergulir (bukan bilah tetap).
  ok(await gulirKe(/^Catat pembayaran tunai\/transfer$/, 20) && await gulirKe(/^Bayar dari tabungan$/, 6), 'Gelap + 2,0× rincian tagihan: kedua tombol tindakan terjangkau dengan menggulir');
  await ukurKembali('Gelap + 2,0× rincian tagihan');
  tautan('alhasanapps://keuangan/laporan');
  await cari(/Saldo akhir|Penerimaan kas baru/, { timeout: 30000 });
  ok(true, 'Gelap + 2,0× laporan tampil');
} catch (e) {
  ok(false, 'Uji berhenti: ' + (e?.message ?? e));
} finally {
  skala(1); gelap(false);
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
