// Mengukur FAB "Ajukan izin" terhadap layar dan bilah tab sistem dari keluaran
// `maestro hierarchy` (iOS simulator). Satuan: titik (pt).
//   node ukur-fab.mjs <hierarki.json>
import fs from 'node:fs';
const akar = JSON.parse(fs.readFileSync(process.argv[2], 'utf8').replace(/^[^{]*/, ''));
const simpul = [];
(function jalan(n) { if (!n) return; simpul.push(n); (n.children ?? []).forEach(jalan); })(akar);
const kotak = n => { const m = /\[(-?\d+),(-?\d+)\]\[(-?\d+),(-?\d+)\]/.exec(n.attributes?.bounds ?? ''); return m ? m.slice(1).map(Number) : null; };
const teks = n => `${n.attributes?.accessibilityText ?? ''}|${n.attributes?.text ?? ''}|${n.attributes?.title ?? ''}`;
let lulus = 0, gagal = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
// Layar = kotak terkecil yang berawal di (0,0) dan setinggi jendela (bukan isi gulir yang lebih panjang).
const layar = simpul.map(kotak).filter(b => b && b[0] === 0 && b[1] === 0 && b[2] > 300 && b[3] > 600).sort((a, b) => a[3] - b[3] || b[2] - a[2])[0];
const fab = simpul.filter(n => teks(n).split('|').includes('Ajukan izin baru') && kotak(n)).map(kotak).sort((a, b) => (a[2] - a[0]) * (a[3] - a[1]) - (b[2] - b[0]) * (b[3] - b[1]))[0];
// Butir bilah tab: label tab di paruh bawah layar.
const tab = simpul.filter(n => /^(Beranda|Izin|Profil|Jadwal)\|?/.test(teks(n)) && kotak(n) && kotak(n)[1] > layar[3] * 0.7).map(kotak);
const atasBilah = Math.min(...tab.map(b => b[1]));
if (!fab || !tab.length) { ok(false, `FAB (${Boolean(fab)}) dan bilah tab (${tab.length}) ditemukan di hierarki`); process.exit(1); }
const lebar = layar[2] - layar[0];
console.log(`layar ${lebar}×${layar[3] - layar[1]} pt · FAB [${fab}] · tepi atas butir bilah tab ${atasBilah}`);
ok(fab[3] - fab[1] >= 56 && fab[3] - fab[1] <= 60, `Tinggi FAB ${fab[3] - fab[1]} pt (≥ 56)`);
ok(Math.abs(lebar - fab[2] - 16) <= 1, `FAB ${lebar - fab[2]} pt dari tepi kanan (16)`);
ok(fab[2] - fab[0] <= lebar / 2, `Lebar FAB ${fab[2] - fab[0]} pt ≤ setengah layar (${lebar / 2})`);
ok(fab[3] <= atasBilah, `FAB (bawah ${fab[3]}) tidak bertabrakan dengan bilah tab Liquid Glass (butir mulai ${atasBilah}); jarak ${atasBilah - fab[3]} pt`);
console.log(`Hasil ukur FAB: ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
