/*!
 * Sistem Al Hasan — Penilaian & rapor (redesain website Tahap 7, R-1 … R-8).
 *
 * Peningkatan progresif saja. Tanpa JavaScript setiap formulir tetap POST
 * biasa yang diproses server (kisi disimpan per santri, tindakan massal
 * berurutan dalam satu kiriman). Dengan JavaScript:
 *   - kisi nilai/sikap: nilai akhir & predikat dihitung langsung, Enter
 *     pindah ke baris berikutnya, peringatan perubahan belum disimpan
 *     (keputusan U), alasan koreksi muncul bila isian tersimpan diubah;
 *   - formulir [data-rapor-massal]: setiap pilihan dikirim SATU PER SATU
 *     lewat AlHasan.bulk dengan bilah kemajuan (keputusan V), sehingga
 *     penerbitan PDF tidak melewati batas waktu cPanel;
 *   - HP: kisi tampil satu santri per kartu dengan Sebelumnya/Berikutnya.
 * Server tetap satu-satunya penentu hak akses dan validasi.
 */
(function () {
    'use strict';
    var doc = document;
    var AlHasan = window.AlHasan || {};
    function qsa(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

    /* --- Perubahan belum disimpan (U) ------------------------------------ */
    var dirtyForms = [];
    var submitting = false;
    function markDirty(form) {
        if (!form.classList.contains('is-dirty')) {
            form.classList.add('is-dirty');
            dirtyForms.push(form);
            form.dispatchEvent(new CustomEvent('rapor:dirty'));
        }
    }
    qsa('form[data-ah-dirty], form[data-rapor-kisi]').forEach(function (form) {
        form.addEventListener('input', function (event) { if (event.target.name && event.target.name !== '_csrf') { markDirty(form); } });
        form.addEventListener('change', function (event) { if (event.target.name && event.target.name !== '_csrf') { markDirty(form); } });
        form.addEventListener('submit', function () { submitting = true; });
    });
    window.addEventListener('beforeunload', function (event) {
        if (submitting) { return; }
        if (dirtyForms.some(function (form) { return form.classList.contains('is-dirty'); })) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* --- Kisi nilai (R-2/R-3) -------------------------------------------- */
    // Sama dengan NilaiService::calculate(): bilangan bulat perseratus, half-up.
    function hitung(row) {
        var harian = [], tengah = null, akhir = null;
        qsa('input[data-komponen]', row).forEach(function (input) {
            var raw = String(input.value || '').trim().replace(',', '.');
            if (raw === '' || !/^\d{1,3}(\.\d{1,2})?$/.test(raw) || parseFloat(raw) > 100) { return; }
            var v = Math.round(parseFloat(raw) * 100);
            var k = input.getAttribute('data-komponen');
            if (k === 'Harian') { harian.push(v); } else if (k === 'Tengah') { tengah = v; } else if (k === 'Akhir') { akhir = v; }
        });
        if (!harian.length || tengah === null || akhir === null) { return null; }
        var count = harian.length;
        var sum = harian.reduce(function (a, b) { return a + b; }, 0);
        var num = 4 * sum + 3 * (tengah + akhir) * count;
        var den = 1000 * count;
        var nilai = Math.floor((2 * num + den) / (2 * den));
        return {nilai: nilai, predikat: nilai >= 90 ? 'A' : (nilai >= 80 ? 'B' : (nilai >= 70 ? 'C' : 'D'))};
    }

    qsa('form[data-rapor-kisi]').forEach(function (form) {
        var koreksi = form.querySelector('[data-rapor-koreksi]');
        var alasan = koreksi && koreksi.querySelector('textarea');
        var sbarText = doc.querySelector('[data-rapor-sbar-text]');
        var baseText = sbarText ? sbarText.textContent : '';

        function perluKoreksi() {
            return qsa('tbody tr', form).some(function (row) {
                return qsa('input[data-asal], select[data-asal]', row).some(function (input) {
                    var asal = input.getAttribute('data-asal');
                    var nilai = String(input.value || '').trim().replace(',', '.');
                    if (nilai === String(asal).replace(',', '.')) { return false; }
                    return asal !== '' || row.hasAttribute('data-diserahkan');
                });
            });
        }
        function sync() {
            var butuh = perluKoreksi();
            if (koreksi) {
                koreksi.hidden = !butuh;
                if (alasan) { alasan.required = butuh; }
            }
            if (sbarText) {
                sbarText.textContent = form.classList.contains('is-dirty')
                    ? 'Ada perubahan belum disimpan' + (butuh ? ' · perlu alasan koreksi' : '') + ' · ' + baseText
                    : baseText;
            }
        }
        if (koreksi) { koreksi.hidden = true; }

        qsa('tbody tr', form).forEach(function (row) {
            row.addEventListener('input', function () {
                var hasil = hitung(row);
                var akhir = row.querySelector('[data-rapor-akhir]');
                var pred = row.querySelector('[data-rapor-predikat]');
                if (akhir) { akhir.textContent = hasil ? String(hasil.nilai) : '—'; }
                if (pred) { pred.textContent = hasil ? hasil.predikat : '—'; }
            });
        });
        form.addEventListener('input', sync);
        form.addEventListener('change', sync);

        // Enter pindah ke sel yang sama pada santri berikutnya (Tab tetap ke kanan).
        form.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || !event.target.matches('input[data-komponen], select[data-aspek]')) { return; }
            event.preventDefault();
            var cell = event.target.closest('td');
            var row = event.target.closest('tr');
            var index = Array.prototype.indexOf.call(row.children, cell);
            var next = row.nextElementSibling;
            while (next) {
                var target = next.children[index] && next.children[index].querySelector('input:not([readonly]), select:not([disabled])');
                if (target) { target.focus(); if (target.select) { target.select(); } return; }
                next = next.nextElementSibling;
            }
        });
        form.addEventListener('submit', function (event) {
            sync();
            if (alasan && alasan.required && !alasan.value.trim()) {
                event.preventDefault();
                submitting = false;
                koreksi.hidden = false;
                alasan.focus();
                if (AlHasan.toast) { AlHasan.toast('Tulis alasan koreksi untuk nilai tersimpan yang diubah.', 'warn'); }
            }
        });
        sync();
    });

    /* --- HP: satu santri per kartu ---------------------------------------- */
    var mobile = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
    qsa('table[data-rapor-pager]').forEach(function (table) {
        var rows = qsa('tbody tr', table);
        if (rows.length < 2) { return; }
        var current = 0;
        var nav = doc.createElement('div');
        nav.className = 'ah-kisi-pager ah-no-print';
        nav.innerHTML = '<button type="button" class="btn btn-outline-secondary" data-arah="-1"><i class="fas fa-chevron-left" aria-hidden="true"></i> Sebelumnya</button>'
            + '<span class="ah-kisi-pager__pos" aria-live="polite"></span>'
            + '<button type="button" class="btn btn-outline-secondary" data-arah="1">Berikutnya <i class="fas fa-chevron-right" aria-hidden="true"></i></button>';
        var wrap = table.closest('.ah-table-wrap') || table;
        wrap.parentNode.insertBefore(nav, wrap.nextSibling);
        var pos = nav.querySelector('.ah-kisi-pager__pos');
        function show(i) {
            current = Math.max(0, Math.min(rows.length - 1, i));
            var on = !mobile || mobile.matches;
            table.classList.toggle('is-paged', on);
            nav.hidden = !on;
            rows.forEach(function (row, n) { row.classList.toggle('is-current', n === current); });
            pos.textContent = (current + 1) + ' dari ' + rows.length;
            nav.querySelector('[data-arah="-1"]').disabled = current === 0;
            nav.querySelector('[data-arah="1"]').disabled = current === rows.length - 1;
        }
        nav.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-arah]');
            if (!button) { return; }
            show(current + parseInt(button.getAttribute('data-arah'), 10));
            var first = rows[current].querySelector('input:not([readonly]), select:not([disabled]), a');
            if (first) { first.focus(); }
        });
        if (mobile && mobile.addEventListener) { mobile.addEventListener('change', function () { show(current); }); }
        show(0);
    });

    /* --- Proses massal satu per satu (V) ----------------------------------- */
    // Pilihan: <input name="pilih[]"> tersembunyi, atau kotak centang yang dicentang.
    qsa('form[data-rapor-massal]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || !AlHasan.bulk || !AlHasan.post) { return; }
            event.preventDefault();
            var kisi = doc.querySelector('form[data-rapor-kisi].is-dirty, form[data-ah-dirty].is-dirty');
            if (kisi && kisi !== form) {
                if (AlHasan.toast) { AlHasan.toast('Simpan draf lebih dahulu; ada perubahan yang belum disimpan.', 'warn'); }
                return;
            }
            var scope = form.getAttribute('data-rapor-pilihan') ? doc.querySelector(form.getAttribute('data-rapor-pilihan')) : form;
            var inputs = qsa('input[name="pilih[]"]', form).concat(scope && scope !== form ? qsa('input[name="pilih[]"]', scope) : [])
                .filter(function (input, i, all) { return all.indexOf(input) === i && (input.type !== 'checkbox' || input.checked); });
            if (!inputs.length) {
                if (AlHasan.toast) { AlHasan.toast('Pilih data lebih dahulu.', 'warn'); }
                return;
            }
            var fixed = {};
            qsa('input, textarea, select', form).forEach(function (field) {
                if (!field.name || field.name === '_csrf' || field.name === 'pilih[]' || field.disabled) { return; }
                if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) { return; }
                fixed[field.name] = field.value;
            });
            var holder = doc.getElementById(form.getAttribute('data-rapor-target') || 'rapor-bulk');
            if (!holder) { holder = doc.createElement('div'); form.parentNode.insertBefore(holder, form.nextSibling); }
            var dialog = form.closest('dialog');
            if (dialog && dialog.close) { dialog.close(); }
            var url = form.getAttribute('action') || window.location.pathname;
            holder.scrollIntoView({block: 'nearest'});
            AlHasan.bulk({
                container: holder,
                title: form.getAttribute('data-rapor-massal'),
                items: inputs.map(function (input) { return {id: input.value, label: input.getAttribute('data-nama') || input.value}; }),
                run: function (item) {
                    var data = Object.assign({}, fixed);
                    data.pilih = item.id;
                    return AlHasan.post(url, data);
                },
                onDone: function () {
                    dirtyForms.forEach(function (f) { f.classList.remove('is-dirty'); });
                    var reload = doc.createElement('a');
                    reload.className = 'btn btn-outline-primary';
                    reload.href = window.location.href.split('#')[0];
                    reload.textContent = 'Muat ulang daftar';
                    var actions = holder.querySelector('.ah-bulk__actions');
                    if (actions) { actions.appendChild(reload); }
                }
            });
        });
    });

    // R-6: "Pratinjau" pada bilah pilihan membuka panel santri pertama yang dicentang.
    qsa('[data-rapor-pratinjau-pilihan]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var scope = link.closest('[data-ah-selection]');
            var first = scope && scope.querySelector('input[name="pilih[]"]:checked');
            if (!first) { return; }
            event.preventDefault();
            var url = link.getAttribute('data-rapor-pratinjau-pilihan');
            window.location.href = url + (url.indexOf('?') === -1 ? '?' : '&') + 'santri_id=' + encodeURIComponent(first.value.split('|')[0]) + '#ah-panel';
        });
    });

    /* --- R-1: tambah mapel, guru per mapel, pratinjau tanda tangan ---------- */
    function syncGuru(row) {
        var mapel = row.querySelector('select[data-rapor-mapel]');
        var guru = row.querySelector('select[data-rapor-guru]');
        if (!mapel || !guru) { return; }
        Array.prototype.forEach.call(guru.options, function (option) {
            if (option.value) { option.hidden = option.getAttribute('data-mapel') !== mapel.value; }
        });
        if (guru.selectedOptions[0] && guru.selectedOptions[0].hidden) { guru.value = ''; }
    }
    var rowsBox = doc.querySelector('[data-rapor-mapel-rows]');
    if (rowsBox) {
        qsa('tr', rowsBox).forEach(syncGuru);
        rowsBox.addEventListener('change', function (event) {
            if (event.target.matches('select[data-rapor-mapel]')) { syncGuru(event.target.closest('tr')); }
        });
        var counter = 0;
        qsa('[data-rapor-tambah-mapel]').forEach(function (button) {
            button.addEventListener('click', function () {
                var template = doc.getElementById('rapor-mapel-template');
                if (!template) { return; }
                counter += 1;
                var holder = doc.createElement('tbody');
                holder.innerHTML = template.innerHTML.replace(/__N__/g, 'baru' + counter);
                var row = holder.firstElementChild;
                rowsBox.appendChild(row);
                syncGuru(row);
                var first = row.querySelector('select');
                if (first) { first.focus(); }
            });
        });
    }
    qsa('select[data-rapor-ttd]').forEach(function (select) {
        var box = doc.getElementById(select.getAttribute('data-rapor-ttd'));
        if (!box) { return; }
        select.addEventListener('change', function () {
            var option = select.selectedOptions[0];
            var src = option && option.getAttribute('data-src');
            box.innerHTML = '';
            if (src) {
                var img = doc.createElement('img');
                img.src = src;
                img.width = 180;
                img.height = 70;
                img.alt = 'Pratinjau tanda tangan ' + (option.textContent || '');
                box.appendChild(img);
            } else {
                var hint = doc.createElement('span');
                hint.className = 'text-muted small';
                hint.textContent = 'Pratinjau tanda tangan tampil setelah dipilih.';
                box.appendChild(hint);
            }
        });
    });

    /* --- Kisi sikap (R-4): baris berubah ditandai -------------------------- */
    qsa('form[data-rapor-kisi] select[data-aspek]').forEach(function (select) {
        select.addEventListener('change', function () { select.closest('tr').classList.add('is-changed'); });
    });
})();
