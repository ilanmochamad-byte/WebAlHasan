/*!
 * Sistem Al Hasan — perilaku kerangka dan komponen bersama
 * (redesain website Tahap 1, 22 Oktober 2026).
 *
 * Dimuat oleh App\Ui\Layout di akhir setiap halaman internal, sesudah
 * `window.ALHASAN_CSRF` disetel. Semua yang ada di sini hanya PENINGKATAN
 * tampilan: tanpa JavaScript setiap tautan, formulir, menu ⋯ (<details>), dan
 * panel samping (halaman biasa) tetap berfungsi. Pengaman sebenarnya — CSRF,
 * idempotensi, transaksi, hak akses — tetap di server.
 *
 * API untuk halaman: window.AlHasan.{toast, confirm, post, bulk, selected}.
 */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;
    var AlHasan = window.AlHasan = window.AlHasan || {};

    function qsa(selector, scope) {
        return Array.prototype.slice.call((scope || doc).querySelectorAll(selector));
    }

    function el(tag, className, text) {
        var node = doc.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function icon(name) {
        var i = el('i', 'fas ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function storageGet(key) {
        try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    }

    function storageSet(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* penyimpanan lokal opsional */ }
    }

    /* --- Laci navigasi (HP & tablet) ------------------------------------ */
    // Tanpa JavaScript, rel ikon (tablet), bilah bawah (HP), dan Beranda tetap
    // menjangkau seluruh menu, sehingga tidak ada fungsi yang hilang.
    var shell = doc.getElementById('ah-shell');
    var toggle = doc.getElementById('ah-nav-toggle');
    var scrim = doc.getElementById('ah-scrim');
    var sidebar = doc.getElementById('ah-sidebar');
    var main = doc.getElementById('ah-konten');
    var smallScreen = window.matchMedia('(max-width: 991.98px)');

    function drawerOpen() {
        return !!shell && shell.classList.contains('is-nav-open');
    }

    function setOpen(open) {
        if (!shell || !sidebar) { return; }
        shell.classList.toggle('is-nav-open', open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            var glyph = toggle.querySelector('i');
            if (glyph) { glyph.className = 'fas ' + (open ? 'fa-xmark' : 'fa-bars'); }
            var label = toggle.querySelector('.ah-visually-hidden');
            if (label) { label.textContent = open ? 'Tutup menu navigasi' : 'Buka menu navigasi'; }
        }
        if (scrim) { scrim.hidden = !open; }
        sidebar.inert = smallScreen.matches && !open;
        if (main) { main.inert = smallScreen.matches && open; }
        if (smallScreen.matches && open) {
            var first = sidebar.querySelector('a');
            if (first) { first.focus(); }
        }
    }

    if (sidebar) {
        sidebar.inert = smallScreen.matches;
        smallScreen.addEventListener('change', function () { setOpen(false); });
    }
    if (toggle) {
        toggle.addEventListener('click', function () { setOpen(!drawerOpen()); });
    }
    if (scrim) {
        scrim.addEventListener('click', function () { setOpen(false); if (toggle) { toggle.focus(); } });
    }
    // Geser ke kiri menutup laci.
    if (sidebar) {
        var touchX = null;
        sidebar.addEventListener('touchstart', function (event) { touchX = event.touches[0].clientX; }, {passive: true});
        sidebar.addEventListener('touchend', function (event) {
            if (touchX !== null && drawerOpen() && touchX - event.changedTouches[0].clientX > 60) { setOpen(false); }
            touchX = null;
        }, {passive: true});
    }

    /* --- Grup sidebar yang dapat dilipat (desktop) ----------------------- */
    // Diingat per peramban, bukan per akun, tanpa perubahan database.
    var GROUP_KEY = 'alhasan.nav.grupTertutup';
    var closedGroups = storageGet(GROUP_KEY);
    if (!Array.isArray(closedGroups)) { closedGroups = []; }

    function setGroup(section, open) {
        var button = section.querySelector('.ah-nav-group__btn');
        section.classList.toggle('is-collapsed', !open);
        if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    qsa('.ah-nav-sec').forEach(function (section) {
        var slug = section.getAttribute('data-ah-group');
        var button = section.querySelector('.ah-nav-group__btn');
        // Grup yang memuat halaman aktif selalu terbuka.
        if (closedGroups.indexOf(slug) !== -1 && !section.hasAttribute('data-ah-active')) {
            setGroup(section, false);
        }
        if (!button) { return; }
        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            setGroup(section, open);
            closedGroups = closedGroups.filter(function (item) { return item !== slug; });
            if (!open) { closedGroups.push(slug); }
            storageSet(GROUP_KEY, closedGroups);
        });
    });

    /* --- Tooltip rel ikon (tablet) --------------------------------------- */
    var tip = null;
    function hideTip() { if (tip) { tip.hidden = true; } }
    qsa('.ah-rail a[data-ah-tip]').forEach(function (link) {
        // Tooltip bawaan peramban hanya cadangan tanpa JavaScript.
        link.removeAttribute('title');
        function show() {
            if (!tip) {
                tip = el('div', 'ah-tip');
                tip.setAttribute('aria-hidden', 'true');
                doc.body.appendChild(tip);
            }
            var rect = link.getBoundingClientRect();
            tip.textContent = link.getAttribute('data-ah-tip');
            tip.hidden = false;
            tip.style.left = Math.round(rect.right + 8) + 'px';
            tip.style.top = Math.round(rect.top + rect.height / 2 - tip.offsetHeight / 2) + 'px';
        }
        link.addEventListener('mouseenter', show);
        link.addEventListener('focus', show);
        link.addEventListener('mouseleave', hideTip);
        link.addEventListener('blur', hideTip);
    });
    var rail = doc.querySelector('.ah-rail');
    if (rail) { rail.addEventListener('scroll', hideTip, {passive: true}); }

    /* --- Menu ⋯ dan menu profil (<details>) ------------------------------ */
    function placeMenu(menu) {
        var list = menu.querySelector('.ah-menu__list');
        var summary = menu.querySelector('summary');
        if (!list || !summary || !menu.classList.contains('ah-menu--row')) { return; }
        // Menu baris berada di dalam wadah tabel yang menggulir; posisi tetap
        // mencegahnya terpotong oleh wadah itu.
        var rect = summary.getBoundingClientRect();
        list.style.position = 'fixed';
        list.style.right = 'auto';
        var width = list.offsetWidth;
        var height = list.offsetHeight;
        var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
        var top = rect.bottom + 4;
        if (top + height > window.innerHeight - 8 && rect.top - height - 4 > 8) { top = rect.top - height - 4; }
        list.style.left = Math.round(left) + 'px';
        list.style.top = Math.round(top) + 'px';
    }

    function closeMenus(except) {
        qsa('details.ah-menu[open]').forEach(function (menu) {
            if (menu !== except) { menu.open = false; }
        });
    }

    doc.addEventListener('toggle', function (event) {
        var menu = event.target;
        if (!(menu instanceof HTMLDetailsElement) || !menu.classList.contains('ah-menu')) { return; }
        var summary = menu.querySelector('summary');
        if (summary) { summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false'); }
        if (menu.open) {
            closeMenus(menu);
            placeMenu(menu);
        }
    }, true);
    qsa('details.ah-menu > summary').forEach(function (summary) {
        summary.setAttribute('aria-haspopup', 'true');
        summary.setAttribute('aria-expanded', 'false');
    });
    doc.addEventListener('click', function (event) {
        var inside = event.target.closest && event.target.closest('details.ah-menu');
        closeMenus(inside || null);
    });
    window.addEventListener('resize', function () { closeMenus(null); hideTip(); });
    window.addEventListener('scroll', function () {
        qsa('details.ah-menu--row[open]').forEach(placeMenu);
        hideTip();
    }, {passive: true, capture: true});

    /* --- Pemberitahuan (toast) ------------------------------------------- */
    var TOAST_ICON = {ok: 'fa-circle-check', danger: 'fa-circle-exclamation', warn: 'fa-triangle-exclamation', info: 'fa-circle-info'};

    /**
     * Toast 5 detik di pojok kanan bawah. `silent` dipakai bila pesan yang
     * sama sudah diumumkan di halaman, agar pembaca layar tidak membacanya dua kali.
     */
    AlHasan.toast = function (message, tone, options) {
        var region = doc.getElementById('ah-toasts');
        if (!region || !message) { return null; }
        tone = TOAST_ICON[tone] ? tone : 'info';
        var toast = el('div', 'ah-toast ah-toast--' + tone);
        if (options && options.silent) { toast.setAttribute('aria-hidden', 'true'); }
        toast.appendChild(icon(TOAST_ICON[tone]));
        toast.appendChild(el('span', 'ah-toast__text', message));
        var close = el('button', 'ah-toast__close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Tutup pemberitahuan');
        if (options && options.silent) { close.tabIndex = -1; }
        close.appendChild(icon('fa-xmark'));
        close.addEventListener('click', function () { toast.remove(); });
        toast.appendChild(close);
        region.appendChild(toast);
        window.setTimeout(function () { toast.remove(); }, (options && options.duration) || 5000);
        return toast;
    };

    qsa('[data-ah-toast]').forEach(function (note) {
        var text = note.querySelector('.ah-note__title + span');
        AlHasan.toast(text ? text.textContent : note.textContent, note.getAttribute('data-ah-toast'), {silent: true});
    });

    /* --- Dialog konfirmasi ----------------------------------------------- */
    var dialog = null;

    function buildDialog() {
        dialog = el('dialog', 'ah-dialog');
        dialog.setAttribute('aria-labelledby', 'ah-dialog-title');
        dialog.setAttribute('aria-describedby', 'ah-dialog-text');
        dialog.innerHTML = '<form method="dialog" class="ah-dialog__box">'
            + '<h2 class="ah-dialog__title" id="ah-dialog-title"></h2>'
            + '<p class="ah-dialog__text" id="ah-dialog-text"></p>'
            + '<div class="ah-dialog__reason" hidden>'
            + '<label class="form-label" for="ah-dialog-reason"></label>'
            + '<textarea class="form-control" id="ah-dialog-reason" rows="3" maxlength="500"></textarea>'
            + '<span class="ah-field-error" id="ah-dialog-reason-error" hidden>Alasan wajib diisi.</span>'
            + '</div>'
            + '<div class="ah-dialog__actions">'
            + '<button class="btn btn-outline-secondary" value="batal" data-ah-cancel>Batal</button>'
            + '<button class="btn btn-primary" value="ok" data-ah-ok>Lanjutkan</button>'
            + '</div></form>';
        doc.body.appendChild(dialog);
        return dialog;
    }

    /**
     * Dialog konfirmasi bersama, pengganti confirm() bawaan peramban.
     * Menyebut objek dan akibatnya; tombol berbahaya berwarna merah; alasan
     * dapat diwajibkan. Mengembalikan Promise<{ok:boolean, reason:string}>.
     */
    AlHasan.confirm = function (options) {
        options = options || {};
        var opener = doc.activeElement;
        if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) {
            var plain = window.confirm([options.title, options.text].filter(Boolean).join('\n\n'));
            return Promise.resolve({ok: plain, reason: ''});
        }
        var box = dialog || buildDialog();
        var reasonWrap = box.querySelector('.ah-dialog__reason');
        var reason = box.querySelector('#ah-dialog-reason');
        var reasonError = box.querySelector('#ah-dialog-reason-error');
        var ok = box.querySelector('[data-ah-ok]');
        var cancel = box.querySelector('[data-ah-cancel]');
        box.querySelector('.ah-dialog__title').textContent = options.title || 'Lanjutkan tindakan ini?';
        var text = box.querySelector('.ah-dialog__text');
        text.textContent = options.text || '';
        text.hidden = !options.text;
        ok.textContent = options.ok || 'Lanjutkan';
        ok.className = 'btn ' + (options.tone === 'danger' ? 'btn-danger' : 'btn-primary');
        cancel.textContent = options.cancel || 'Batal';
        reasonWrap.hidden = !options.reasonLabel;
        reason.value = '';
        reason.required = !!options.reasonLabel;
        reason.removeAttribute('aria-invalid');
        reasonError.hidden = true;
        box.querySelector('label[for="ah-dialog-reason"]').textContent = options.reasonLabel || '';

        return new Promise(function (resolve) {
            function onOk(event) {
                if (!reasonWrap.hidden && reason.value.trim() === '') {
                    event.preventDefault();
                    reasonError.hidden = false;
                    reason.setAttribute('aria-invalid', 'true');
                    reason.setAttribute('aria-describedby', 'ah-dialog-reason-error');
                    reason.focus();
                }
            }
            function onClose() {
                ok.removeEventListener('click', onOk);
                box.removeEventListener('close', onClose);
                var accepted = box.returnValue === 'ok';
                if (opener && opener.focus && doc.contains(opener)) { opener.focus(); }
                resolve({ok: accepted, reason: accepted ? reason.value.trim() : ''});
            }
            ok.addEventListener('click', onOk);
            box.addEventListener('close', onClose);
            box.returnValue = '';
            box.showModal();
            (reasonWrap.hidden ? cancel : reason).focus();
        });
    };

    /* --- Formulir POST: CSRF, konfirmasi, klik ganda --------------------- */
    // Wadah tabel dapat digulir dengan keyboard, termasuk saat tidak ada tautan.
    qsa('.ah-table-wrap, .table-responsive').forEach(function (wrap) {
        wrap.tabIndex = 0;
        wrap.setAttribute('role', 'region');
        var caption = wrap.querySelector('caption');
        wrap.setAttribute('aria-label', caption ? caption.textContent : 'Tabel data; geser untuk melihat kolom lainnya');
    });
    // Tabel bertanda .ah-table--cards menjadi kartu di HP; label kolom diambil
    // dari kepala tabel bila sel belum menyebutkannya.
    qsa('table.ah-table--cards').forEach(function (table) {
        var heads = qsa('thead th', table).map(function (th) { return th.textContent.trim(); });
        qsa('tbody tr', table).forEach(function (row) {
            Array.prototype.forEach.call(row.cells, function (cell, index) {
                if (!cell.hasAttribute('data-label') && heads[index]) { cell.setAttribute('data-label', heads[index]); }
            });
        });
    });
    qsa('[data-error-for]').forEach(function (message) {
        var form = message.closest('form');
        var field = form && form.elements.namedItem(message.dataset.errorFor);
        if (field && field.setAttribute) {
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + message.id).trim());
            field.classList.add('ah-input-invalid');
        }
    });

    function busy(form, submitter) {
        // Tombol disabled tidak termasuk successful controls. Pertahankan
        // hanya submitter yang benar-benar dipilih sebelum menonaktifkannya.
        if (submitter && submitter.name) {
            var action = doc.createElement('input');
            action.type = 'hidden';
            action.name = submitter.name;
            action.value = submitter.value;
            action.setAttribute('data-ah-submitter', '');
            form.appendChild(action);
        }
        var buttons = qsa('button[type="submit"], button:not([type])', form);
        if (form.id) {
            buttons = buttons.concat(qsa('button[form="' + form.id + '"]'));
        }
        buttons.forEach(function (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
        if (submitter && submitter.tagName === 'BUTTON' && submitter.textContent.trim() !== '') {
            submitter.setAttribute('data-ah-label', submitter.innerHTML);
            submitter.textContent = submitter.getAttribute('data-busy-label') || 'Menyimpan…';
        }
        // Formulir yang membuka tab baru (cetak) tetap dapat dipakai lagi.
        if (form.target === '_blank') {
            window.setTimeout(function () { release(form); }, 1500);
        }
    }

    function release(form) {
        qsa('[data-ah-submitter]', form).forEach(function (input) { input.remove(); });
        qsa('button[aria-busy="true"]').forEach(function (button) {
            if (button.form !== form) { return; }
            button.disabled = false;
            button.removeAttribute('aria-busy');
            if (button.hasAttribute('data-ah-label')) {
                button.innerHTML = button.getAttribute('data-ah-label');
                button.removeAttribute('data-ah-label');
            }
        });
    }

    qsa('form[method="post"], form[method="POST"]').forEach(function (form) {
        // Token CSRF disisipkan otomatis ke setiap formulir POST yang belum punya.
        if (!form.querySelector('input[name="_csrf"]')) {
            var input = doc.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf';
            input.value = window.ALHASAN_CSRF;
            form.appendChild(input);
        }
        // Klik ganda tidak mengirim dua permintaan. Pengaman sebenarnya tetap
        // kunci idempotensi dan transaksi di server; ini hanya bantuan tampilan.
        form.addEventListener('submit', function (event) {
            // Konfirmasi yang dibatalkan tidak boleh mengunci formulir.
            if (event.defaultPrevented) { return; }
            var submitter = event.submitter;
            if (form.dataset.confirm && form.dataset.ahConfirmed !== '1') {
                event.preventDefault();
                AlHasan.confirm({
                    title: form.dataset.confirmTitle || 'Lanjutkan tindakan ini?',
                    text: form.dataset.confirm,
                    ok: form.dataset.confirmOk,
                    tone: form.dataset.confirmTone,
                    reasonLabel: form.dataset.confirmReasonLabel
                }).then(function (result) {
                    if (!result.ok) {
                        if (submitter && submitter.focus) { submitter.focus(); }
                        return;
                    }
                    var reasonName = form.dataset.confirmReason;
                    if (reasonName) {
                        var reasonInput = form.querySelector('input[type="hidden"][name="' + reasonName + '"]');
                        if (!reasonInput) {
                            reasonInput = doc.createElement('input');
                            reasonInput.type = 'hidden';
                            reasonInput.name = reasonName;
                            form.appendChild(reasonInput);
                        }
                        reasonInput.value = result.reason;
                    }
                    form.dataset.ahConfirmed = '1';
                    if (form.requestSubmit) {
                        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                    } else {
                        busy(form, submitter);
                        form.submit();
                    }
                });
                return;
            }
            delete form.dataset.ahConfirmed;
            busy(form, submitter);
        });
    });
    // Kembali ke halaman lewat tombol Back (bfcache) tidak boleh menemukan
    // formulir yang masih terkunci.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { qsa('form').forEach(release); }
    });

    /* --- Panel samping --------------------------------------------------- */
    // Tanpa JavaScript panel tampil sebagai bagian halaman biasa di alamatnya
    // sendiri (?action=create/edit); dengan JavaScript ia menjadi panel di
    // kanan dan daftar tetap terlihat. Menutup = kembali ke alamat daftar.
    var panel = doc.querySelector('.ah-panel[data-ah-close]');
    if (panel) {
        root.classList.add('ah-panel-open');
        var panelScrim = el('div', 'ah-panel-scrim');
        panelScrim.addEventListener('click', function () { window.location.href = panel.getAttribute('data-ah-close'); });
        panel.parentNode.insertBefore(panelScrim, panel);
        doc.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || event.defaultPrevented || drawerOpen()) { return; }
            if (doc.querySelector('dialog[open], details.ah-menu[open]')) { return; }
            window.location.href = panel.getAttribute('data-ah-close');
        });
    }

    /* --- Tab modul di HP menjadi chip yang dapat digeser ----------------- */
    qsa('.ah-tabs').forEach(function (tabs) {
        var current = tabs.querySelector('[aria-current="page"]');
        if (current && tabs.scrollWidth > tabs.clientWidth) {
            tabs.scrollLeft = current.parentNode.offsetLeft - (tabs.clientWidth - current.offsetWidth) / 2;
        }
    });

    /* --- Keyboard: Escape dan perangkap fokus laci ----------------------- */
    doc.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var openMenu = doc.querySelector('details.ah-menu[open]');
            if (openMenu) {
                openMenu.open = false;
                var summary = openMenu.querySelector('summary');
                if (summary) { summary.focus(); }
                event.preventDefault();
                return;
            }
            if (drawerOpen()) {
                setOpen(false);
                if (toggle) { toggle.focus(); }
                event.preventDefault();
                return;
            }
        }
        if (event.key === 'Tab' && smallScreen.matches && drawerOpen()) {
            var links = sidebar.querySelectorAll('a[href]');
            var first = links[0], last = links[links.length - 1];
            if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    /* --- Bilah pilihan massal -------------------------------------------- */
    /**
     * Nilai kotak centang yang dipilih di dalam wadah [data-ah-selection].
     */
    AlHasan.selected = function (scope) {
        scope = typeof scope === 'string' ? doc.querySelector(scope) : scope;
        return scope ? qsa('input[type="checkbox"][data-ah-select]:checked', scope).map(function (box) { return box.value; }) : [];
    };

    qsa('[data-ah-selection]').forEach(function (scope) {
        var noun = scope.getAttribute('data-ah-selection') || 'data';
        var all = scope.querySelector('input[type="checkbox"][data-ah-select-all]');
        var bar = scope.querySelector('.ah-selbar');
        var count = bar && bar.querySelector('[data-ah-selcount]');
        function update() {
            var boxes = qsa('input[type="checkbox"][data-ah-select]', scope);
            var chosen = boxes.filter(function (box) { return box.checked; });
            if (bar) { bar.hidden = chosen.length === 0; }
            if (count) { count.textContent = chosen.length + ' ' + noun + ' dipilih'; }
            if (all) {
                all.checked = boxes.length > 0 && chosen.length === boxes.length;
                all.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
            }
            scope.dispatchEvent(new CustomEvent('ah:selection', {detail: {values: AlHasan.selected(scope)}}));
        }
        scope.addEventListener('change', function (event) {
            if (event.target === all) {
                qsa('input[type="checkbox"][data-ah-select]', scope).forEach(function (box) {
                    if (!box.disabled) { box.checked = all.checked; }
                });
            }
            update();
        });
        qsa('[data-ah-select-clear]', scope).forEach(function (button) {
            button.addEventListener('click', function () {
                qsa('input[type="checkbox"][data-ah-select]', scope).forEach(function (box) { box.checked = false; });
                update();
                if (all) { all.focus(); }
            });
        });
        update();
    });

    /* --- Permintaan POST dari halaman ------------------------------------ */
    /**
     * POST ber-CSRF yang mengharapkan JSON. Ditolak (reject) dengan Error yang
     * pesannya dapat ditampilkan, bila server menjawab bukan 2xx atau ok:false.
     */
    AlHasan.post = function (url, data) {
        var body = new URLSearchParams();
        body.append('_csrf', window.ALHASAN_CSRF || '');
        Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': window.ALHASAN_CSRF || ''},
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (json) {
                if (!response.ok || (json && json.ok === false)) {
                    throw new Error((json && (json.message || json.error)) || ('Server menjawab ' + response.status + '.'));
                }
                return json;
            });
        });
    };

    /* --- Proses massal --------------------------------------------------- */
    /**
     * Menjalankan tindakan untuk banyak data SATU PER SATU dengan bilah
     * kemajuan "17 / 24". Setiap data tetap diproses (dan diaudit) oleh
     * server secara terpisah; yang gagal didaftar beserta sebabnya dan dapat
     * diulang. Berurutan agar tidak melewati batas waktu cPanel.
     *
     * options: {container, title, items:[{id, label}], run(item) → Promise,
     *           onDone({ok:[], failed:[{item, reason}], stopped:[]})}
     */
    AlHasan.bulk = function (options) {
        var container = typeof options.container === 'string' ? doc.querySelector(options.container) : options.container;
        var items = (options.items || []).slice();
        var total = items.length;
        var stopped = false;
        var result = {ok: [], failed: [], stopped: []};

        var section = el('section', 'ah-bulk');
        var headingId = 'ah-bulk-' + Date.now();
        var heading = el('h2', 'ah-bulk__title', options.title || 'Memproses data');
        heading.id = headingId;
        section.setAttribute('aria-labelledby', headingId);
        var progress = el('progress', 'ah-bulk__progress');
        progress.max = Math.max(total, 1);
        progress.value = 0;
        progress.setAttribute('aria-labelledby', headingId);
        var count = el('span', 'ah-bulk__count', '0 / ' + total);
        count.setAttribute('aria-live', 'polite');
        var bar = el('div', 'ah-bulk__bar');
        bar.appendChild(progress);
        bar.appendChild(count);
        var current = el('p', 'ah-bulk__current');
        var actions = el('div', 'ah-bulk__actions');
        var stop = el('button', 'btn btn-outline-secondary', 'Hentikan');
        stop.type = 'button';
        stop.addEventListener('click', function () {
            stopped = true;
            stop.disabled = true;
            stop.textContent = 'Menghentikan setelah data ini…';
        });
        actions.appendChild(stop);
        var report = el('div', 'ah-bulk__result');
        [heading, bar, current, report, actions].forEach(function (node) { section.appendChild(node); });
        if (container) {
            container.innerHTML = '';
            container.appendChild(section);
        }

        function finish() {
            current.textContent = '';
            actions.innerHTML = '';
            var summary = el('p', 'ah-bulk__summary',
                result.ok.length + ' berhasil · ' + result.failed.length + ' gagal'
                + (result.stopped.length ? ' · ' + result.stopped.length + ' belum diproses' : ''));
            summary.setAttribute('role', 'status');
            report.appendChild(summary);
            if (result.failed.length) {
                var list = el('ul', 'ah-bulk__failed');
                result.failed.forEach(function (failure) {
                    var row = el('li');
                    row.appendChild(el('b', null, failure.item.label || String(failure.item.id)));
                    row.appendChild(doc.createTextNode(' — ' + failure.reason));
                    list.appendChild(row);
                });
                report.appendChild(list);
            }
            var again = result.failed.map(function (failure) { return failure.item; }).concat(result.stopped);
            if (again.length) {
                var retry = el('button', 'btn btn-primary', 'Ulangi ' + again.length + ' yang belum berhasil');
                retry.type = 'button';
                retry.addEventListener('click', function () {
                    AlHasan.bulk(Object.assign({}, options, {items: again}));
                });
                actions.appendChild(retry);
            }
            AlHasan.toast(summary.textContent, result.failed.length ? 'warn' : 'ok', {silent: true});
            if (typeof options.onDone === 'function') { options.onDone(result); }
            return result;
        }

        function next(index) {
            if (index >= total) { return Promise.resolve(finish()); }
            if (stopped) {
                result.stopped = items.slice(index);
                return Promise.resolve(finish());
            }
            var item = items[index];
            current.textContent = 'Memproses: ' + (item.label || item.id);
            return Promise.resolve()
                .then(function () { return options.run(item); })
                .then(function () { result.ok.push(item); }, function (error) {
                    result.failed.push({item: item, reason: (error && error.message) || 'Gagal tanpa keterangan.'});
                })
                .then(function () {
                    progress.value = index + 1;
                    count.textContent = (index + 1) + ' / ' + total;
                    return next(index + 1);
                });
        }

        var done = next(0);
        return {stop: function () { stop.click(); }, done: done, element: section};
    };

    if (window.jQuery) {
        window.jQuery.ajaxSetup({headers: {'X-CSRF-Token': window.ALHASAN_CSRF}});
    }
})();
