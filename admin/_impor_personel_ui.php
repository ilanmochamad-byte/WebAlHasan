<?php

declare(strict_types=1);

use App\Profil\ImporPersonelService;

/**
 * Wizard impor identitas Guru/Pengurus (PRD-PROFIL-IMPORT Fase 1), dipakai
 * `admin_guru.php?action=impor` dan `admin_pengurus.php?action=impor`.
 *
 * Langkah: unggah → pemetaan kolom → pratinjau per baris (tindakan, perubahan,
 * konflik, pilihan pasangan/konfirmasi) → simpan. Setiap pilihan admin memicu
 * pemeriksaan ulang di server; tombol Simpan memakai sidik pratinjau terakhir.
 * Seluruh teks dari berkas dan server ditulis lewat `textContent`.
 */

// Potongan halaman khusus admin; permintaan langsung tetap melewati guard admin.
require_once __DIR__ . '/_guard.php';
function impor_personel_render(string $jenis, string $halaman): void
{
    $label = $jenis === 'guru' ? 'ustadz/ustadzah' : 'pengurus';
    $kolom = array_merge(ImporPersonelService::KOLOM, ['nip_nik']);
    $judul = array_intersect_key(ImporPersonelService::JUDUL, array_flip($kolom));
    ?>
    <div data-ah-impor-personel data-jenis="<?= ah_e($jenis) ?>" data-label="<?= ah_e($label) ?>">
        <div data-impor-stepper><?= ah_stepper(['Unggah & petakan', 'Periksa', 'Selesai'], 0, 'Langkah impor') ?></div>
        <noscript><?php ah_note('warning', 'Impor membutuhkan JavaScript untuk membaca berkas Excel di peramban.'); ?></noscript>
        <section class="ah-card" data-impor-langkah="1" aria-labelledby="impor-unggah">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="impor-unggah">Unggah berkas</h2></div>
            <div class="ah-card__body">
                <?php ah_note('info', 'Impor hanya memperbarui identitas — tidak membuat akun, username, atau penugasan.',
                    '<p class="small mb-0 mt-2">Kolom Tugas, Kamar/Kobong, dan Tugas Tambahan hanya ditampilkan sebagai bahan pemeriksaan. Atur tugas di menu Penugasan. Sel kosong tidak menghapus data lama. Sedikitnya NIP atau NIK wajib ada (NIK 16 digit).</p>'); ?>
                <label class="form-label" for="impor-berkas">Berkas Excel (.xlsx atau .xls, maksimal 5 MB dan <?= ImporPersonelService::BATAS_BARIS ?> baris)</label>
                <input class="form-control" type="file" id="impor-berkas" accept=".xlsx,.xls">
                <p class="small text-danger mt-2 mb-0" data-impor-galat role="alert" hidden></p>
                <div data-impor-peta hidden class="mt-3">
                    <h3 class="h6">Pemetaan kolom</h3>
                    <p class="small text-muted" data-impor-info></p>
                    <div class="ah-table-wrap"><table class="ah-table"><caption class="ah-visually-hidden">Pemetaan judul kolom berkas ke isian sistem</caption>
                        <thead><tr><th scope="col">Kolom di berkas</th><th scope="col">Contoh isi</th><th scope="col">Dibaca sebagai</th></tr></thead>
                        <tbody data-impor-peta-baris></tbody></table></div>
                </div>
                <div class="ah-actbar mt-3"><a class="btn btn-outline-secondary" href="<?= ah_e($halaman) ?>">Batal</a>
                    <button class="btn btn-primary" type="button" data-impor-periksa disabled>Periksa data</button></div>
            </div>
        </section>
        <section data-impor-langkah="2" hidden aria-labelledby="impor-hasil">
            <h2 class="h6" id="impor-hasil" data-impor-judul>Hasil pemeriksaan</h2>
            <div data-impor-stats></div>
            <p class="small text-danger" data-impor-galat2 role="alert" hidden></p>
            <div class="ah-card ah-table-wrap">
                <table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Tindakan per baris</caption>
                    <thead><tr><th scope="col">Baris</th><th scope="col">Nama · NIP/NIK</th><th scope="col">Tindakan</th><th scope="col">Rincian</th><th scope="col">Informasi berkas</th></tr></thead>
                    <tbody data-impor-tabel></tbody></table>
            </div>
            <div class="ah-actbar ah-actbar--sticky">
                <span class="ah-actbar__text" data-impor-ringkas role="status"></span>
                <button class="btn btn-outline-secondary" type="button" data-impor-kembali>Kembali</button>
                <button class="btn btn-outline-primary" type="button" data-impor-ulang>Periksa ulang pilihan</button>
                <button class="btn btn-primary" type="button" data-impor-simpan>Simpan</button>
                <a class="btn btn-primary" href="<?= ah_e($halaman) ?>" data-impor-selesai hidden>Selesai</a>
            </div>
        </section>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
    (function () {
        var akar = document.querySelector('[data-ah-impor-personel]');
        if (!akar) { return; }
        var jenis = akar.getAttribute('data-jenis'), label = akar.getAttribute('data-label');
        var JUDUL = <?= json_encode($judul, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var ALIAS = {
            nama: ['namalengkap', 'nama', 'namaguru', 'namapengurus', 'namaustadz'], nip: ['nip'], nik: ['nik', 'noktp', 'nomorktp', 'noinduk kependudukan'],
            nip_nik: ['nipnik', 'niknip', 'nipataunik', 'nikataunip', 'nomoridentitas', 'noidentitas'], tempat_lahir: ['tempatlahir'], tanggal_lahir: ['tanggallahir', 'tgllahir'],
            alamat_rumah: ['alamatrumah', 'alamat'], kelurahan: ['kelurahandesa', 'kelurahan', 'desa', 'desakelurahan'], kecamatan: ['kecamatan'],
            kabupaten: ['kabupatenkota', 'kabupaten', 'kota', 'kabkota'], provinsi: ['provinsi'], no_hp: ['nohp', 'nomorhp', 'hp', 'nowa', 'nohpwa', 'telepon', 'notelp'],
            email: ['email', 'surel'], tugas: ['tugas'], kamar: ['kamarkobong', 'kamar', 'kobong'], tugas_tambahan: ['tugastambahan']
        };
        var q = function (s) { return akar.querySelector(s); };
        var sel = null, kepala = [], isi = [], berkas = {}, pilihan = {}, terakhir = null;
        var el = function (tag, teks, kelas) { var e = document.createElement(tag); if (teks != null) { e.textContent = teks; } if (kelas) { e.className = kelas; } return e; };
        var kunciJudul = function (t) { return String(t == null ? '' : t).toLowerCase().replace(/[^a-z0-9]/g, ''); };
        var galat = function (t, n) { var g = q(n === 2 ? '[data-impor-galat2]' : '[data-impor-galat]'); g.textContent = t || ''; g.hidden = !t; };
        var langkah = function (n) {
            q('[data-impor-langkah="1"]').hidden = n !== 1;
            q('[data-impor-langkah="2"]').hidden = n === 1;
            akar.querySelectorAll('[data-impor-stepper] li').forEach(function (li, i) {
                li.className = i < n - 1 ? 'is-done' : (i === n - 1 ? 'is-current' : '');
                if (i === n - 1) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
            });
        };
        var tebak = function (judul) {
            var k = kunciJudul(judul);
            var hasil = '';
            Object.keys(ALIAS).forEach(function (kanon) { if (!hasil && ALIAS[kanon].map(kunciJudul).indexOf(k) !== -1) { hasil = kanon; } });
            return hasil;
        };
        var nilaiSel = function (v) {
            // Angka di luar presisi aman JavaScript (mis. NIK berupa sel angka) ditandai agar server menolaknya.
            if (typeof v === 'number') { return Number.isSafeInteger(v) || !Number.isInteger(v) ? v : '#PRESISI'; }
            return v == null ? '' : String(v);
        };
        var bangunBaris = function () {
            var peta = {};
            akar.querySelectorAll('[data-impor-kolom]').forEach(function (s) { if (s.value) { peta[s.getAttribute('data-impor-kolom')] = s.value; } });
            return isi.map(function (r) {
                var o = {baris: r.nomor};
                Object.keys(peta).forEach(function (i) { o[peta[i]] = nilaiSel(r.sel[i]); });
                return o;
            });
        };
        var tampilPeta = function () {
            var tb = q('[data-impor-peta-baris]'); tb.textContent = '';
            kepala.forEach(function (j, i) {
                var tr = document.createElement('tr');
                tr.appendChild(el('td', j || ('Kolom ' + (i + 1))));
                var contoh = isi.length ? isi[0].sel[i] : '';
                tr.appendChild(el('td', contoh == null ? '' : String(contoh)));
                var td = document.createElement('td');
                var s = document.createElement('select'); s.className = 'form-select form-select-sm'; s.setAttribute('data-impor-kolom', String(i));
                s.setAttribute('aria-label', 'Kolom ' + (j || (i + 1)) + ' dibaca sebagai');
                s.appendChild(new Option('— Abaikan —', ''));
                Object.keys(JUDUL).forEach(function (k) { s.appendChild(new Option(JUDUL[k], k)); });
                s.value = tebak(j);
                td.appendChild(s); tr.appendChild(td); tb.appendChild(tr);
            });
            q('[data-impor-info]').textContent = isi.length + ' baris data ditemukan. Periksa pemetaan kolom; kolom NIK yang berisi NIP pada sebagian baris dapat dipetakan ke "NIP atau NIK (dikenali otomatis)".';
            q('[data-impor-peta]').hidden = false;
            q('[data-impor-periksa]').disabled = isi.length === 0;
        };
        q('#impor-berkas').addEventListener('change', function () {
            var file = this.files[0]; galat(''); q('[data-impor-peta]').hidden = true; q('[data-impor-periksa]').disabled = true;
            if (!file) { return; }
            if (file.size > 5 * 1024 * 1024) { galat('Ukuran berkas maksimal 5 MB.'); return; }
            file.arrayBuffer().then(function (buf) {
                var hashJanji = (window.crypto && crypto.subtle) ? crypto.subtle.digest('SHA-256', buf).then(function (h) {
                    return Array.prototype.map.call(new Uint8Array(h), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
                }).catch(function () { return null; }) : Promise.resolve(null);
                // Nilai dibaca apa adanya (raw): tidak ada rumus/makro yang dijalankan.
                var wb = XLSX.read(buf, {type: 'array', cellFormula: false, cellHTML: false, cellDates: false});
                var data = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header: 1, raw: true, defval: '', blankrows: false});
                var awal = data.findIndex(function (r) { return r.some(function (v) { return String(v).trim() !== ''; }); });
                if (awal === -1) { throw new Error('Berkas kosong.'); }
                kepala = data[awal].map(function (v) { return String(v).trim(); });
                isi = [];
                for (var i = awal + 1; i < data.length; i++) {
                    if (data[i].some(function (v) { return String(v).trim() !== ''; })) { isi.push({nomor: i + 1, sel: data[i]}); }
                }
                if (isi.length > <?= ImporPersonelService::BATAS_BARIS ?>) { throw new Error('Berkas berisi ' + isi.length + ' baris; maksimal <?= ImporPersonelService::BATAS_BARIS ?>. Bagi berkas menjadi beberapa bagian.'); }
                return hashJanji.then(function (h) { berkas = {ukuran: file.size, sha256: h}; tampilPeta(); });
            }).catch(function (e) { galat(e && e.message ? e.message : 'Berkas tidak dapat dibaca. Periksa formatnya lalu coba lagi.'); });
        });
        var kirim = function (mode) {
            var body = new FormData();
            body.append('mode', mode); body.append('jenis', jenis); body.append('_csrf', window.ALHASAN_CSRF || '');
            body.append('payload', JSON.stringify({rows: bangunBaris(), pilihan: pilihan, berkas: berkas}));
            return fetch('proses_import_personel.php', {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.json().catch(function () { return {ok: false, pesan: 'Jawaban server tidak terbaca.'}; }).then(function (d) { if (!r.ok || !d.ok) { throw new Error(d.pesan || 'Server menolak permintaan.'); } return d; }); });
        };
        var LABEL_AKSI = {tambah: ['Tambah baru', 'ok'], perbarui: ['Perbarui', 'info'], tidak_berubah: ['Tidak berubah', 'muted'], perlu_konfirmasi: ['Perlu konfirmasi', 'warn'],
            pilih_pasangan: ['Pilih pasangan', 'warn'], konflik: ['Konflik', 'danger'], ditambah: ['Ditambahkan', 'ok'], diperbarui: ['Diperbarui', 'ok']};
        var ubahPilihan = function () {
            q('[data-impor-simpan]').disabled = true;
            q('[data-impor-ringkas]').textContent = 'Pilihan berubah — tekan "Periksa ulang pilihan" sebelum menyimpan.';
        };
        var simpanSidik = function (d) { d.hasil.forEach(function (b) { pilihan[b.baris] = pilihan[b.baris] || {}; pilihan[b.baris].sidik = b.sidik; }); };
        var kontrol = function (b, td) {
            var p = pilihan[b.baris] = pilihan[b.baris] || {};
            var nama = 'impor-pilih-' + b.baris;
            if (b.kandidat && b.kandidat.length && (b.aksi === 'pilih_pasangan' || p.aksi)) {
                var fs = document.createElement('fieldset'); fs.className = 'mt-2';
                fs.appendChild(el('legend', 'Pasangan data lama', 'small fw-semibold mb-1'));
                var opsi = function (nilai, teks, boleh) {
                    var w = el('div', null, 'form-check'); var i = document.createElement('input');
                    i.type = 'radio'; i.className = 'form-check-input'; i.name = nama; i.id = nama + '-' + nilai; i.disabled = !boleh;
                    i.checked = (nilai === 'tambah' && p.aksi === 'tambah') || (p.aksi === 'pasangan' && String(p.pasangan_id) === String(nilai));
                    i.addEventListener('change', function () { if (nilai === 'tambah') { p.aksi = 'tambah'; delete p.pasangan_id; } else { p.aksi = 'pasangan'; p.pasangan_id = nilai; } ubahPilihan(); });
                    var l = el('label', teks, 'form-check-label small'); l.htmlFor = i.id; w.appendChild(i); w.appendChild(l); fs.appendChild(w);
                };
                b.kandidat.forEach(function (k) { opsi(k.id, 'ID ' + k.id + ' · ' + k.nama + ' · NIP ' + (k.nip || '—') + ' · NIK ' + (k.nik || '—') + (k.aktif ? '' : ' (nonaktif)') + (k.catatan ? ' — ' + k.catatan : ''), k.dapat_dipilih); });
                opsi('tambah', 'Tambah sebagai ' + label + ' baru (orang berbeda)', true);
                td.appendChild(fs);
            }
            if (b.aksi === 'perlu_konfirmasi' || p.timpa || p.ubah_pengenal) {
                var cek = function (kunci, teks) {
                    var w = el('div', null, 'form-check mt-1'); var i = document.createElement('input');
                    i.type = 'checkbox'; i.className = 'form-check-input'; i.id = nama + '-' + kunci; i.checked = !!p[kunci];
                    i.addEventListener('change', function () { p[kunci] = i.checked; ubahPilihan(); });
                    var l = el('label', teks, 'form-check-label small'); l.htmlFor = i.id; w.appendChild(i); w.appendChild(l); td.appendChild(w);
                };
                if ((b.menimpa || []).some(function (f) { return f !== 'nip' && f !== 'nik'; })) { cek('timpa', 'Timpa nilai lama'); }
                if ((b.menimpa || []).some(function (f) { return f === 'nip' || f === 'nik'; })) { cek('ubah_pengenal', 'Ganti NIP/NIK data lama'); }
            }
        };
        var tampil = function (d, selesai) {
            terakhir = d;
            if (!selesai) { simpanSidik(d); }
            var stat = function (l, v, w) { return '<div class="ah-stat' + (w ? ' ah-stat--warn' : '') + '"><p class="ah-stat__label">' + l + '</p><p class="ah-stat__value">' + Number(v || 0) + '</p></div>'; };
            var j = d.jumlah;
            q('[data-impor-judul]').textContent = selesai ? 'Hasil impor' : 'Hasil pemeriksaan';
            q('[data-impor-stats]').innerHTML = '<section class="ah-stats" aria-label="Ringkasan">' + stat('Baris', d.baris)
                + (selesai ? stat('Ditambahkan', d.ditambah) + stat('Diperbarui', d.diperbarui) : stat('Tambah', j.tambah) + stat('Perbarui', j.perbarui))
                + stat('Tidak berubah', j.tidak_berubah) + stat('Perlu tindakan', j.perlu_konfirmasi + j.pilih_pasangan, (j.perlu_konfirmasi + j.pilih_pasangan) > 0)
                + stat('Konflik', j.konflik, j.konflik > 0) + '</section>';
            var tb = q('[data-impor-tabel]'); tb.textContent = '';
            // Setiap sel berisi satu pembungkus agar tata letak kartu HP (flex) tidak memecah isinya.
            var sel = function (label, kelas) { var td = el('td'); td.setAttribute('data-label', label); var w = el('div', null, kelas || ''); td.appendChild(w); return [td, w]; };
            d.hasil.forEach(function (b) {
                var tr = document.createElement('tr');
                var no = sel('Baris'); no[1].textContent = String(b.baris); tr.appendChild(no[0]);
                var id = sel('Data', 'text-start');
                id[1].appendChild(el('div', b.nama || '—', 'fw-semibold')); id[1].appendChild(el('div', 'NIP ' + (b.nip || '—') + ' · NIK ' + (b.nik || '—'), 'small text-muted'));
                if (b.sasaran) { id[1].appendChild(el('div', 'Data lama: ID ' + b.sasaran.id + (b.sasaran.cocok_lewat ? ' (cocok lewat ' + b.sasaran.cocok_lewat + ')' : '') + (b.sasaran.akun ? ' · punya akun' : ''), 'small')); }
                tr.appendChild(id[0]);
                var a = LABEL_AKSI[b.aksi] || [b.aksi, 'muted'];
                var ta = sel('Tindakan'); ta[1].appendChild(el('span', a[0], 'ah-badge ah-badge--' + a[1])); tr.appendChild(ta[0]);
                var rs = sel('', 'w-100 text-start'), r = rs[1];
                if (b.perubahan && b.perubahan.length) {
                    var ul = el('ul', null, 'small mb-1 ps-3');
                    b.perubahan.forEach(function (u) { ul.appendChild(el('li', u.label + ': ' + (u.lama == null ? '(kosong)' : u.lama) + ' → ' + u.baru)); });
                    r.appendChild(ul);
                }
                (b.masalah || []).forEach(function (m) { r.appendChild(el('div', m, 'small text-danger')); });
                (b.peringatan || []).forEach(function (m) { r.appendChild(el('div', m, 'small text-muted')); });
                if (!selesai) { kontrol(b, r); }
                tr.appendChild(rs[0]);
                var inf = b.informasi || {};
                var ti = sel('', 'w-100 text-start small text-muted');
                ti[1].textContent = ['Tugas: ' + (inf.tugas || '—'), 'Kamar/Kobong: ' + (inf.kamar || '—'), 'Tugas tambahan: ' + (inf.tugas_tambahan || '—')].join(' · ');
                tr.appendChild(ti[0]);
                tb.appendChild(tr);
            });
            var siap = selesai ? 0 : d.siap;
            q('[data-impor-ringkas]').textContent = selesai ? (d.disimpan + ' baris disimpan. Penugasan dan akun tidak berubah.') : (siap + ' baris siap disimpan');
            q('[data-impor-simpan]').textContent = 'Simpan ' + siap + ' baris';
            q('[data-impor-simpan]').hidden = selesai; q('[data-impor-simpan]').disabled = siap === 0;
            q('[data-impor-ulang]').hidden = selesai; q('[data-impor-kembali]').hidden = selesai;
            q('[data-impor-selesai]').hidden = !selesai;
            langkah(selesai ? 3 : 2);
        };
        var periksa = function (btn) {
            galat(''); galat('', 2); btn.disabled = true; var teks = btn.textContent; btn.textContent = 'Memeriksa…';
            return kirim('periksa').then(function (d) { tampil(d, false); })
                .catch(function (e) { galat(e.message, terakhir ? 2 : 1); })
                .finally(function () { btn.disabled = false; btn.textContent = teks; });
        };
        q('[data-impor-periksa]').addEventListener('click', function () { pilihan = {}; periksa(this); });
        q('[data-impor-ulang]').addEventListener('click', function () { periksa(this); });
        q('[data-impor-kembali]').addEventListener('click', function () { terakhir = null; langkah(1); });
        q('[data-impor-simpan]').addEventListener('click', function () {
            var btn = this; btn.disabled = true; btn.textContent = 'Menyimpan…'; galat('', 2);
            kirim('simpan').then(function (d) { tampil(d, true); })
                .catch(function (e) { btn.disabled = false; btn.textContent = 'Simpan'; galat(e.message + ' Periksa ulang lalu coba lagi.', 2); });
        });
    })();
    </script>
    <?php
}
