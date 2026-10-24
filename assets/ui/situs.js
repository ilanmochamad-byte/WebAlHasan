/*!
 * Situs publik & PSB Al Hasan — perilaku peramban (redesain website Tahap 8).
 *
 * Semua fungsi bersifat tambahan: tanpa JavaScript, menu tampil sebagai
 * daftar, formulir PSB tampil utuh dalam satu halaman, portal menampilkan
 * semua bagian, dan setiap kiriman tetap diperiksa server. Tidak ada
 * alert()/confirm() bawaan peramban.
 */
(function () {
    'use strict';

    var doc = document;
    var $ = function (sel, root) { return (root || doc).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); };

    /* --- Toast ---------------------------------------------------------- */

    function wadahToast() {
        var w = $('#s-toasts');
        if (!w) {
            w = doc.createElement('div');
            w.id = 's-toasts';
            w.className = 'ah-toasts';
            w.setAttribute('aria-live', 'polite');
            doc.body.appendChild(w);
        }
        return w;
    }

    function toast(teks, jenis) {
        var t = doc.createElement('div');
        t.className = 'ah-toast ah-toast--' + (jenis || 'ok');
        t.setAttribute('role', jenis === 'danger' ? 'alert' : 'status');
        var isi = doc.createElement('span');
        isi.className = 'ah-toast__text';
        isi.textContent = teks;
        var tutup = doc.createElement('button');
        tutup.type = 'button';
        tutup.className = 'ah-toast__close';
        tutup.setAttribute('aria-label', 'Tutup pesan');
        tutup.textContent = '×';
        tutup.addEventListener('click', function () { t.remove(); });
        t.appendChild(isi);
        t.appendChild(tutup);
        wadahToast().appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }

    $$('[data-s-toast]').forEach(function (el) {
        toast(el.getAttribute('data-s-toast-teks') || el.textContent.trim(), el.getAttribute('data-s-toast'));
    });

    /* --- Dialog konfirmasi ------------------------------------------------ */

    function konfirmasi(judul, teks, ok, lanjut) {
        var d = doc.createElement('dialog');
        d.className = 's-dialog';
        d.setAttribute('aria-labelledby', 's-dialog-judul');
        d.innerHTML = '<h2 id="s-dialog-judul"></h2><p></p><div class="s-acts">'
            + '<button type="button" class="s-btn" data-batal>Batal</button>'
            + '<button type="button" class="s-btn s-btn--pri" data-ok></button></div>';
        $('h2', d).textContent = judul;
        $('p', d).textContent = teks;
        $('[data-ok]', d).textContent = ok;
        doc.body.appendChild(d);
        var selesai = function (ya) { d.close(); d.remove(); if (ya) { lanjut(); } };
        $('[data-batal]', d).addEventListener('click', function () { selesai(false); });
        $('[data-ok]', d).addEventListener('click', function () { selesai(true); });
        d.addEventListener('cancel', function (e) { e.preventDefault(); selesai(false); });
        if (typeof d.showModal === 'function') {
            d.showModal();
            $('[data-batal]', d).focus();
        } else {
            selesai(true);
        }
    }

    // Formulir yang butuh konfirmasi: selalu (keluar), atau hanya bila isian
    // tertentu diubah (data-s-konfirmasi-ubah, mis. No. HP wali).
    $$('form[data-s-konfirmasi-judul]').forEach(function (form) {
        var nama = form.getAttribute('data-s-konfirmasi-ubah');
        var isian = nama ? form.elements[nama] : null;
        var awal = isian ? isian.value : null;
        var lolos = false;
        form.addEventListener('submit', function (e) {
            if (lolos || (isian && isian.value === awal)) { return; }
            e.preventDefault();
            konfirmasi(form.getAttribute('data-s-konfirmasi-judul') || 'Lanjutkan?',
                form.getAttribute('data-s-konfirmasi-teks') || '',
                form.getAttribute('data-s-konfirmasi-ok') || 'Ya, lanjutkan',
                function () { lolos = true; if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); } });
        });
    });

    // Tombol kirim tidak dapat ditekan dua kali.
    $$('form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.target === '_blank') { return; }
            var tombol = e.submitter || $('button[type=submit], button:not([type])', form);
            if (!tombol || tombol.hasAttribute('data-s-tanpa-kunci')) { return; }
            setTimeout(function () {
                if (e.defaultPrevented) { return; }
                tombol.disabled = true;
                if (tombol.getAttribute('data-s-memproses')) { tombol.textContent = tombol.getAttribute('data-s-memproses'); }
            }, 0);
            // Nilai tombol (mis. name="daftar") tetap terkirim karena dinonaktifkan sesudah dikirim.
        });
    });

    /* --- Navbar ------------------------------------------------------------- */

    var burger = $('[data-s-burger]');
    var nav = burger ? doc.getElementById(burger.getAttribute('aria-controls')) : null;
    function setMenu(buka) {
        if (!nav) { return; }
        nav.classList.toggle('is-buka', buka);
        burger.setAttribute('aria-expanded', buka ? 'true' : 'false');
        doc.body.classList.toggle('s-menu-buka', buka);
        $('.s-vh', burger).textContent = buka ? 'Tutup menu' : 'Buka menu';
    }
    if (burger && nav) {
        burger.addEventListener('click', function () { setMenu(!nav.classList.contains('is-buka')); });
        doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('is-buka')) { setMenu(false); burger.focus(); } });
        window.addEventListener('resize', function () { if (window.innerWidth >= 1100) { setMenu(false); } });
    }
    // Menu tarik-turun (details) menutup saat klik di luar atau Escape.
    doc.addEventListener('click', function (e) {
        $$('.s-drop[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
    });
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        $$('.s-drop[open]').forEach(function (d) { d.removeAttribute('open'); var s = $('summary', d); if (s) { s.focus(); } });
    });

    /* --- Salin & tampilkan --------------------------------------------------- */

    $$('[data-s-salin]').forEach(function (b) {
        b.addEventListener('click', function () {
            var teks = b.getAttribute('data-s-salin') || window.location.href;
            var ok = function () { toast(b.getAttribute('data-s-salin-pesan') || 'Disalin.', 'ok'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(teks).then(ok, function () { toast('Tidak dapat menyalin. Salin secara manual: ' + teks, 'warn'); });
            } else {
                toast('Salin secara manual: ' + teks, 'info');
            }
        });
    });

    $$('[data-s-reveal]').forEach(function (b) {
        var input = doc.getElementById(b.getAttribute('data-s-reveal'));
        if (!input) { return; }
        b.addEventListener('click', function () {
            var tampil = input.type === 'password';
            input.type = tampil ? 'text' : 'password';
            b.textContent = tampil ? 'Sembunyikan' : 'Tampilkan';
            b.setAttribute('aria-pressed', tampil ? 'true' : 'false');
        });
    });

    /* --- Galeri: penampil layar penuh -------------------------------------- */

    var fotos = $$('[data-s-galeri]');
    if (fotos.length) {
        var posisi = 0;
        var viewer = null;
        var tutupViewer = function () { if (viewer) { viewer.remove(); viewer = null; doc.body.style.overflow = ''; if (fotos[posisi]) { fotos[posisi].focus(); } } };
        var tampil = function (i) {
            posisi = (i + fotos.length) % fotos.length;
            var a = fotos[posisi];
            $('img', viewer).src = a.href;
            $('img', viewer).alt = a.getAttribute('data-s-judul') || '';
            $('.s-viewer__cap b', viewer).textContent = a.getAttribute('data-s-judul') || '';
            $('.s-viewer__cap span', viewer).textContent = a.getAttribute('data-s-ket') || '';
            $('.s-viewer__pos', viewer).textContent = (posisi + 1) + ' dari ' + fotos.length;
        };
        var buka = function (i) {
            viewer = doc.createElement('div');
            viewer.className = 's-viewer';
            viewer.setAttribute('role', 'dialog');
            viewer.setAttribute('aria-modal', 'true');
            viewer.setAttribute('aria-label', 'Penampil foto');
            viewer.innerHTML = '<div class="s-viewer__bar"><span class="s-viewer__pos"></span><button type="button" data-tutup>Tutup ✕</button></div>'
                + '<div class="s-viewer__img"><img alt=""></div>'
                + '<div class="s-viewer__cap"><b></b><span></span></div>'
                + '<div class="s-viewer__nav"><button type="button" data-prev>‹ Sebelumnya</button><button type="button" data-next>Berikutnya ›</button></div>';
            doc.body.appendChild(viewer);
            doc.body.style.overflow = 'hidden';
            $('[data-tutup]', viewer).addEventListener('click', tutupViewer);
            $('[data-prev]', viewer).addEventListener('click', function () { tampil(posisi - 1); });
            $('[data-next]', viewer).addEventListener('click', function () { tampil(posisi + 1); });
            var x0 = null;
            viewer.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
            viewer.addEventListener('touchend', function (e) {
                if (x0 === null) { return; }
                var dx = e.changedTouches[0].clientX - x0;
                if (Math.abs(dx) > 40) { tampil(posisi + (dx < 0 ? 1 : -1)); }
                x0 = null;
            });
            tampil(i);
            $('[data-tutup]', viewer).focus();
        };
        fotos.forEach(function (a, i) { a.addEventListener('click', function (e) { e.preventDefault(); buka(i); }); });
        doc.addEventListener('keydown', function (e) {
            if (!viewer) { return; }
            if (e.key === 'Escape') { tutupViewer(); }
            if (e.key === 'ArrowLeft') { tampil(posisi - 1); }
            if (e.key === 'ArrowRight') { tampil(posisi + 1); }
        });
    }

    /* --- Wilayah (PSB dan ubah alamat) ------------------------------------- */

    // Struktur: [data-s-wilayah] berisi blok pilihan (empat <select>, tampil
    // dengan JavaScript) dan blok ketik manual (isian teks bernama nama_*,
    // yang sebenarnya dikirim). Tanpa JavaScript atau bila daftar gagal
    // dimuat, wilayah diketik manual.
    var API = 'https://www.emsifa.com/api-wilayah-indonesia/api/';
    var URUTAN = [
        { sel: 'provinsi', isian: 'nama_provinsi', label: 'provinsi', url: function () { return 'provinces.json'; } },
        { sel: 'kabupaten', isian: 'nama_kabupaten', label: 'kabupaten/kota', url: function (id) { return 'regencies/' + id + '.json'; } },
        { sel: 'kecamatan', isian: 'nama_kecamatan', label: 'kecamatan', url: function (id) { return 'districts/' + id + '.json'; } },
        { sel: 'desa', isian: 'nama_desa', label: 'desa/kelurahan', url: function (id) { return 'villages/' + id + '.json'; } }
    ];
    var wilayahKotak = [];
    $$('[data-s-wilayah]').forEach(function (kotak) {
        var wajib = kotak.hasAttribute('data-s-wilayah-wajib');
        var tunda = kotak.hasAttribute('data-s-wilayah-tunda');
        var pesan = $('[data-s-wilayah-pesan]', kotak);
        var manual = $('[data-s-wilayah-manual]', kotak);
        var pilih = $('[data-s-wilayah-pilih]', kotak);
        var aktifkan = $('[data-s-wilayah-aktifkan]', kotak);
        var elSel = function (u) { return $('#' + u.sel, kotak); };
        var elIsi = function (u) { return kotak.querySelector('[name="' + u.isian + '"]'); };
        var dimuat = false;
        var api = { kotak: kotak, mode: 'manual' };
        wilayahKotak.push(api);

        var muat = function (i, id) {
            var u = URUTAN[i];
            var s = elSel(u);
            s.disabled = true;
            s.innerHTML = '<option value="">Memuat…</option>';
            if (pesan) { pesan.hidden = true; }
            return fetch(API + u.url(id)).then(function (r) {
                if (!r.ok) { throw new Error('gagal'); }
                return r.json();
            }).then(function (data) {
                s.innerHTML = '';
                var o = doc.createElement('option');
                o.value = '';
                o.textContent = 'Pilih ' + u.label + '…';
                s.appendChild(o);
                data.forEach(function (w) {
                    var opt = doc.createElement('option');
                    opt.value = w.id;
                    opt.textContent = w.name;
                    opt.setAttribute('data-name', w.name);
                    s.appendChild(opt);
                });
                s.disabled = false;
            }).catch(function () {
                s.innerHTML = '<option value="">Gagal dimuat</option>';
                if (pesan) {
                    pesan.hidden = false;
                    var ulang = $('[data-s-wilayah-ulang]', pesan);
                    if (ulang) { ulang.onclick = function () { muat(i, id); }; }
                }
            });
        };
        var setMode = function (mode) {
            api.mode = mode;
            if (manual) { manual.hidden = mode !== 'manual'; }
            if (pilih) { pilih.hidden = mode !== 'pilih'; }
            URUTAN.forEach(function (u) {
                var t = elIsi(u);
                var s = elSel(u);
                if (t) { t.required = wajib && mode === 'manual'; }
                if (s) { s.required = wajib && mode === 'pilih'; }
            });
            if (mode === 'pilih') {
                URUTAN.forEach(function (u) { var t = elIsi(u); if (t) { t.value = ''; } });
                if (!dimuat) { dimuat = true; muat(0); } else { elSel(URUTAN[0]).value = ''; elSel(URUTAN[0]).dispatchEvent(new Event('change')); }
            }
            if (mode === 'mati') {
                URUTAN.forEach(function (u) { var t = elIsi(u); if (t) { t.value = ''; } });
            }
        };
        api.setMode = setMode;

        URUTAN.forEach(function (u, i) {
            elSel(u).addEventListener('change', function () {
                this.setCustomValidity('');
                var opsi = this.options[this.selectedIndex];
                var t = elIsi(u);
                if (t) { t.value = opsi && this.value ? (opsi.getAttribute('data-name') || '') : ''; }
                for (var j = i + 1; j < URUTAN.length; j++) {
                    var lanjut = elSel(URUTAN[j]);
                    lanjut.innerHTML = '<option value="">Pilih ' + URUTAN[j].label + '…</option>';
                    lanjut.disabled = true;
                    var tl = elIsi(URUTAN[j]);
                    if (tl) { tl.value = ''; }
                }
                if (this.value && i + 1 < URUTAN.length) { muat(i + 1, this.value); }
            });
        });
        var ketik = $('[data-s-wilayah-ketik]', kotak);
        if (ketik) {
            ketik.addEventListener('click', function () { setMode('manual'); var t = elIsi(URUTAN[0]); if (t) { t.focus(); } });
        }

        // Isian yang sudah terisi (kiriman sebelumnya gagal) tetap ditampilkan apa adanya.
        var sudahTerisi = URUTAN.some(function (u) { var t = elIsi(u); return t && t.value !== ''; });
        if (tunda && aktifkan) {
            setMode('mati');
            aktifkan.addEventListener('change', function () { wajib = aktifkan.checked; setMode(aktifkan.checked ? 'pilih' : 'mati'); });
        } else if (!sudahTerisi) {
            setMode('pilih');
        } else {
            setMode('manual');
        }

        // Pemeriksaan sebelum lanjut: setiap tingkat wilayah harus terpilih.
        api.cek = function () {
            if (api.mode !== 'pilih') { return true; }
            for (var i = 0; i < URUTAN.length; i++) {
                var t = elIsi(URUTAN[i]);
                if (t && t.value === '') {
                    var s = elSel(URUTAN[i]);
                    if (!s.disabled) {
                        s.setCustomValidity('Pilih ' + URUTAN[i].label + ' dari daftar.');
                        s.reportValidity();
                    } else {
                        toast('Tunggu daftar ' + URUTAN[i].label + ' selesai dimuat, atau pilih “Ketik manual”.', 'warn');
                    }
                    return false;
                }
            }
            return true;
        };
        var form = kotak.closest('form');
        if (form && !form.hasAttribute('data-s-langkah')) {
            form.addEventListener('submit', function (e) { if (!api.cek()) { e.preventDefault(); } });
        }
    });

    /* --- Formulir PSB bertahap (P-1) -------------------------------------- */

    var psb = $('form[data-s-langkah]');
    if (psb) {
        // Pemeriksaan per langkah dilakukan skrip ini; server tetap memeriksa ulang.
        psb.noValidate = true;
        var langkah = $$('.s-langkah', psb);
        var penanda = $$('[data-s-penanda] li');
        var aktif = 0;
        var galatAwal = psb.getAttribute('data-s-langkah-galat');

        var periksaTambahan = function (bagian) {
            var hp = psb.elements.no_hp_wali;
            var ulang = psb.elements.no_hp_wali_ulang;
            if (ulang && bagian.contains(ulang)) {
                ulang.setCustomValidity(hp && ulang.value !== hp.value ? 'No. HP wali yang diketik ulang belum sama.' : '');
            }
        };
        var sah = function (bagian) {
            periksaTambahan(bagian);
            var wil = wilayahKotak.filter(function (w) { return bagian.contains(w.kotak); })[0];
            if (wil && !wil.cek()) { return false; }
            var isian = $$('input, select, textarea', bagian).filter(function (el) { return !el.disabled && el.type !== 'hidden'; });
            for (var i = 0; i < isian.length; i++) {
                if (!isian[i].checkValidity()) {
                    isian[i].reportValidity();
                    return false;
                }
            }
            return true;
        };
        var ringkas = function () {
            $$('[data-s-ringkas]', psb).forEach(function (dd) {
                var nama = dd.getAttribute('data-s-ringkas');
                var el = psb.elements[nama];
                var nilai = '';
                if (el && el.tagName === 'SELECT') {
                    nilai = el.value ? el.options[el.selectedIndex].text : '';
                } else if (el && el.length && el[0] && el[0].type === 'radio') {
                    $$('input[name="' + nama + '"]', psb).forEach(function (r) { if (r.checked) { nilai = r.parentNode.textContent.trim(); } });
                } else if (el) {
                    nilai = el.value;
                    if (el.type === 'date' && nilai) {
                        var p = nilai.split('-');
                        nilai = p[2] + '-' + p[1] + '-' + p[0];
                    }
                }
                dd.textContent = nilai || '—';
            });
            var wil = ['nama_desa', 'nama_kecamatan', 'nama_kabupaten', 'nama_provinsi'].map(function (n) {
                return psb.elements[n] ? psb.elements[n].value : '';
            }).filter(Boolean).join(', ');
            var w = $('[data-s-ringkas-wilayah]', psb);
            if (w) { w.textContent = wil || '—'; }
        };
        var ke = function (i, fokus) {
            aktif = Math.max(0, Math.min(langkah.length - 1, i));
            langkah.forEach(function (l, j) { l.classList.toggle('is-aktif', j === aktif); });
            penanda.forEach(function (li, j) {
                li.classList.toggle('is-selesai', j < aktif);
                if (j === aktif) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
            });
            if (aktif === langkah.length - 1) { ringkas(); }
            if (fokus !== false) {
                var judul = $('legend, h2', langkah[aktif]);
                if (judul) { judul.setAttribute('tabindex', '-1'); judul.focus(); }
                window.scrollTo({ top: Math.max(0, psb.getBoundingClientRect().top + window.scrollY - 90) });
            }
        };
        $$('[data-s-lanjut]', psb).forEach(function (b) {
            b.addEventListener('click', function () { if (sah(langkah[aktif])) { ke(aktif + 1); } });
        });
        $$('[data-s-kembali]', psb).forEach(function (b) { b.addEventListener('click', function () { ke(aktif - 1); }); });
        $$('[data-s-ke]', psb).forEach(function (b) { b.addEventListener('click', function () { ke(parseInt(b.getAttribute('data-s-ke'), 10)); }); });
        if (psb.elements.no_hp_wali_ulang) {
            psb.elements.no_hp_wali_ulang.addEventListener('input', function () { this.setCustomValidity(''); });
        }
        psb.addEventListener('submit', function (e) {
            for (var i = 0; i < langkah.length; i++) {
                if (!sah(langkah[i])) {
                    e.preventDefault();
                    ke(i);
                    sah(langkah[i]);
                    return;
                }
            }
        });
        ke(galatAwal !== null ? parseInt(galatAwal, 10) || 0 : 0, galatAwal !== null);
    }

    /* --- Portal pendaftar: bagian sebagai tab ------------------------------ */

    var portal = $('.s-portal--tab');
    if (portal) {
        var bagian = $$('.s-portal-sec', portal);
        var tautan = $$('[data-s-bagian]');
        var tampilBagian = function (fokus) {
            var awal = $('[data-s-bagian-awal]');
            var id = window.location.hash ? window.location.hash.slice(1) : (awal ? awal.getAttribute('data-s-bagian-awal') : 'status');
            if (!bagian.some(function (b) { return b.id === id; })) { id = 'status'; }
            bagian.forEach(function (b) { b.classList.toggle('is-aktif', b.id === id); });
            tautan.forEach(function (a) {
                if (a.getAttribute('data-s-bagian') === id) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
            });
            if (fokus) {
                var h = $('#' + id + ' h1');
                if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
                window.scrollTo(0, 0);
            }
        };
        window.addEventListener('hashchange', function () { tampilBagian(true); });
        tampilBagian(false);
    }

    /* --- Unggah per dokumen (P-6): periksa ukuran sebelum kirim ------------- */

    $$('form[data-s-unggah]').forEach(function (form) {
        var maks = parseInt(form.getAttribute('data-s-unggah'), 10) || 0;
        var input = $('input[type=file]', form);
        var pesan = $('[data-s-unggah-pesan]', form.parentNode);
        form.addEventListener('submit', function (e) {
            var f = input.files && input.files[0];
            var salah = '';
            if (!f) {
                salah = 'Pilih berkas lebih dahulu.';
            } else if (maks && f.size > maks) {
                salah = 'Berkas lebih dari 2 MB. Perkecil atau pindai ulang dengan resolusi lebih rendah.';
            } else if (!/\.(jpe?g|png|pdf)$/i.test(f.name)) {
                salah = 'Jenis berkas harus JPG, PNG, atau PDF.';
            }
            if (salah) {
                e.preventDefault();
                if (pesan) { pesan.hidden = false; pesan.className = 's-upload__pesan s-upload__pesan--galat'; pesan.textContent = salah; }
                input.focus();
            }
        });
    });

    /* --- Pembiayaan (P-7): total langsung --------------------------------- */

    var biaya = $('form[data-s-biaya]');
    if (biaya) {
        var rupiah = function (n) { return 'Rp ' + n.toLocaleString('id-ID'); };
        var hitung = function () {
            var pilihan = 0;
            $$('[data-s-nominal]', biaya).forEach(function (el) { var v = parseInt(el.value, 10); pilihan += isNaN(v) || v < 0 ? 0 : v; });
            var wajib = 0;
            $$('input[name="wajib[]"]', biaya).forEach(function (cb) { if (cb.checked) { wajib += parseInt(cb.getAttribute('data-harga'), 10) || 0; } });
            var tw = $('#txtTotalWajib', biaya);
            if (tw) { tw.textContent = rupiah(wajib); }
            $$('[data-s-total]').forEach(function (el) { el.textContent = rupiah(pilihan + wajib); });
        };
        biaya.addEventListener('input', hitung);
        biaya.addEventListener('change', hitung);
        var metode = biaya.elements.metode_pembayaran;
        var info = $('#infoTransfer');
        var aturMetode = function () {
            var nilai = '';
            if (metode && metode.length) {
                $$('input[name=metode_pembayaran]', biaya).forEach(function (r) { if (r.checked) { nilai = r.value; } });
            } else if (metode) {
                nilai = metode.value;
            }
            if (info) { info.hidden = nilai !== 'Transfer'; }
            var tunai = $('#infoTunai');
            if (tunai) { tunai.hidden = nilai === 'Transfer'; }
        };
        biaya.addEventListener('change', aturMetode);
        hitung();
        aturMetode();
    }
})();
