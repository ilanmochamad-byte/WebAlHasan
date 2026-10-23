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

    function setOpen(open, focusTarget) {
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
            var first = focusTarget || sidebar.querySelector('a, summary, button, input');
            if (first) { first.focus(); }
        }
    }

    // Butir "Menu" di bilah bawah HP dan tombol cari di tablet membuka laci yang
    // sama; tombol cari langsung menaruh fokus di kotak pencarian menu.
    qsa('[data-ah-drawer]').forEach(function (opener) {
        opener.addEventListener('click', function (event) {
            event.preventDefault();
            setOpen(true);
        });
    });
    qsa('[data-ah-find]').forEach(function (opener) {
        opener.addEventListener('click', function () {
            setOpen(true, sidebar ? sidebar.querySelector('.ah-search__input') : null);
        });
    });

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
            + '<div class="ah-dialog__match" hidden>'
            + '<label class="form-label" for="ah-dialog-match"></label>'
            + '<input class="form-control" id="ah-dialog-match" type="text" autocomplete="off" spellcheck="false">'
            + '<span class="ah-field-error" id="ah-dialog-match-error" hidden>Ketikan belum sama.</span>'
            + '</div>'
            + '<div class="ah-dialog__actions">'
            + '<button class="btn btn-outline-secondary" value="batal" formnovalidate data-ah-cancel>Batal</button>'
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
        // Tindakan yang tidak dapat dibatalkan dapat meminta pengguna mengetik
        // nama objeknya (mis. hapus pendaftar). Server tetap memeriksa haknya.
        var matchWrap = box.querySelector('.ah-dialog__match');
        var match = box.querySelector('#ah-dialog-match');
        var matchError = box.querySelector('#ah-dialog-match-error');
        matchWrap.hidden = !options.match;
        match.value = '';
        match.removeAttribute('aria-invalid');
        matchError.hidden = true;
        box.querySelector('label[for="ah-dialog-match"]').textContent = options.match
            ? (options.matchLabel || 'Ketik') + ' "' + options.match + '" untuk melanjutkan' : '';

        return new Promise(function (resolve) {
            function onOk(event) {
                if (!matchWrap.hidden && match.value.trim().toLowerCase() !== String(options.match).trim().toLowerCase()) {
                    event.preventDefault();
                    matchError.hidden = false;
                    match.setAttribute('aria-invalid', 'true');
                    match.setAttribute('aria-describedby', 'ah-dialog-match-error');
                    match.focus();
                    return;
                }
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
                if (opener && opener.focus && doc.contains(opener)) {
                    // Menu ⋯ bisa tertutup selama dialog terbuka. Kembalikan
                    // fokus ke pemicu menu bila tombol asal tersembunyi.
                    var menu = opener.closest('details.ah-menu');
                    var focusTarget = menu && !menu.open ? menu.querySelector('summary') : opener;
                    if (focusTarget) { focusTarget.focus(); }
                }
                resolve({ok: accepted, reason: accepted ? reason.value.trim() : ''});
            }
            ok.addEventListener('click', onOk);
            box.addEventListener('close', onClose);
            box.returnValue = '';
            box.showModal();
            (!matchWrap.hidden ? match : (reasonWrap.hidden ? cancel : reason)).focus();
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
            // Formulir massal diproses satu per satu oleh AlHasan.bulk.
            if (form.hasAttribute('data-ah-bulk')) { return; }
            var submitter = event.submitter;
            if (form.dataset.confirm && form.dataset.ahConfirmed !== '1') {
                event.preventDefault();
                AlHasan.confirm({
                    title: form.dataset.confirmTitle || 'Lanjutkan tindakan ini?',
                    text: form.dataset.confirm,
                    ok: form.dataset.confirmOk,
                    tone: form.dataset.confirmTone,
                    reasonLabel: form.dataset.confirmReasonLabel,
                    match: form.dataset.confirmMatch,
                    matchLabel: form.dataset.confirmMatchLabel
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
            var links = qsa('a[href], button:not([disabled]), input:not([type="hidden"]), summary', sidebar).filter(function (node) {
                return node.offsetParent !== null;
            });
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
                // Sesi habis dapat mengalihkan ke halaman masuk dengan HTTP
                // 200. Respons HTML/kosong bukan bukti tindakan berhasil.
                if (!json || typeof json !== 'object') {
                    throw new Error('Server tidak mengembalikan data JSON. Muat ulang halaman dan periksa sesi masuk.');
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

    /* --- Pencarian menu (redesain Tahap 2, keputusan AQ) ----------------- */
    // Hanya mencari nama menu dan tab yang boleh dibuka akun ini; datanya
    // dirender server ke halaman, sehingga tidak ada permintaan tambahan.
    // Pencarian santri dan nomor izin sengaja belum ada (fitur lanjutan).
    var menuData = doc.getElementById('ah-menu-data');
    var menuIndex = [];
    try { menuIndex = menuData ? JSON.parse(menuData.textContent || '[]') : []; } catch (e) { menuIndex = []; }

    function normal(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/&/g, ' dan ');
    }

    qsa('.ah-search').forEach(function (box) {
        var input = box.querySelector('.ah-search__input');
        var list = box.querySelector('.ah-search__results');
        if (!input || !list) { return; }
        var hits = [];
        var active = -1;

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function mark(index) {
            var links = qsa('a', list);
            links.forEach(function (link, i) { link.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
            active = index;
            if (links[index]) {
                input.setAttribute('aria-activedescendant', links[index].id);
                links[index].scrollIntoView({block: 'nearest'});
            }
        }

        function render() {
            var query = normal(input.value).trim();
            list.innerHTML = '';
            if (query === '') { close(); return; }
            var words = query.split(/\s+/);
            hits = menuIndex.filter(function (item) {
                var text = normal(item.label + ' ' + item.context);
                return words.every(function (word) { return text.indexOf(word) !== -1; });
            }).sort(function (a, b) {
                var aStart = normal(a.label).indexOf(query) === 0 ? 0 : 1;
                var bStart = normal(b.label).indexOf(query) === 0 ? 0 : 1;
                return aStart - bStart;
            }).slice(0, 8);
            if (hits.length === 0) {
                var none = el('li', 'ah-search__none', 'Menu tidak ditemukan.');
                none.setAttribute('role', 'option');
                none.setAttribute('aria-disabled', 'true');
                list.appendChild(none);
            }
            hits.forEach(function (item, index) {
                var li = el('li');
                li.setAttribute('role', 'presentation');
                var link = el('a');
                link.href = item.url;
                link.id = input.id + '-opsi-' + index;
                link.setAttribute('role', 'option');
                link.setAttribute('aria-selected', 'false');
                link.tabIndex = -1;
                link.appendChild(el('span', '', item.label));
                if (item.context) { link.appendChild(el('small', '', item.context)); }
                li.appendChild(link);
                list.appendChild(li);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            mark(hits.length ? 0 : -1);
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', function () { if (input.value.trim() !== '') { render(); } });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' && hits.length) {
                event.preventDefault();
                mark((active + 1) % hits.length);
            } else if (event.key === 'ArrowUp' && hits.length) {
                event.preventDefault();
                mark((active - 1 + hits.length) % hits.length);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (hits[active]) { window.location.href = hits[active].url; }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                event.stopPropagation();
                input.value = '';
                close();
            }
        });
        doc.addEventListener('click', function (event) {
            if (!box.contains(event.target)) { close(); }
        });
    });

    /* --- Tampilkan kata sandi (A-1) --------------------------------------- */
    qsa('[data-ah-reveal]').forEach(function (button) {
        var input = doc.getElementById(button.getAttribute('data-ah-reveal'));
        var label = button.querySelector('span');
        var glyph = button.querySelector('i');
        if (!input) { return; }
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            if (label) { label.textContent = show ? 'Sembunyikan' : 'Tampilkan'; }
            if (glyph) { glyph.className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye'); }
            input.focus();
        });
    });

    /* --- Syarat kata sandi sebagai daftar centang (A-3) -------------------- */
    // Hanya bantuan tampilan; server tetap memeriksa syarat yang sama.
    qsa('form[data-ah-password]').forEach(function (form) {
        var input = form.querySelector('input[name="password_baru"]');
        if (!input) { return; }
        var rules = {
            panjang: function (v) { return v.length >= 12; },
            besar: function (v) { return /[A-Z]/.test(v); },
            kecil: function (v) { return /[a-z]/.test(v); },
            angka: function (v) { return /\d/.test(v); }
        };
        function check() {
            qsa('[data-ah-rule]', form).forEach(function (item) {
                var rule = rules[item.getAttribute('data-ah-rule')];
                var ok = rule ? rule(input.value) : false;
                item.classList.toggle('is-ok', ok);
                var state = item.querySelector('[data-ah-rule-state]');
                if (state) { state.textContent = ok ? ' (terpenuhi)' : ' (belum)'; }
            });
        }
        input.addEventListener('input', check);
        check();
    });

    /* --- Redesain Tahap 3: saringan, zona unggah, proses massal formulir -- */
    // Saringan terlipat di HP kecuali ada saringan aktif; tanpa JavaScript
    // selalu terbuka (atribut open dari server).
    if (window.matchMedia && window.matchMedia('(max-width: 575.98px)').matches) {
        qsa('details[data-ah-filter]').forEach(function (filter) {
            if (!filter.hasAttribute('data-ah-filter-active')) { filter.removeAttribute('open'); }
        });
    }

    // Zona unggah: berkas dapat ditarik ke kotak; nama berkas terpilih
    // ditampilkan. Pemeriksaan jenis dan ukuran tetap di server.
    qsa('[data-ah-drop]').forEach(function (zone) {
        var input = zone.querySelector('input[type="file"]');
        if (!input) { return; }
        var list = el('ul', 'ah-drop__list');
        list.setAttribute('aria-live', 'polite');
        zone.appendChild(list);
        function render() {
            list.innerHTML = '';
            Array.prototype.forEach.call(input.files || [], function (file) {
                list.appendChild(el('li', null, file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB'));
            });
        }
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) { event.preventDefault(); zone.classList.add('is-over'); });
        });
        ['dragleave', 'dragend'].forEach(function (name) {
            zone.addEventListener(name, function () { zone.classList.remove('is-over'); });
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-over');
            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length) {
                try { input.files = event.dataTransfer.files; } catch (e) { return; }
                input.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
        input.addEventListener('change', render);
    });

    // Formulir tindakan massal: dengan JavaScript setiap data yang dipilih
    // dikirim SATU PER SATU lewat AlHasan.bulk (bilah kemajuan, daftar gagal,
    // ulangi). Tanpa JavaScript formulir terkirim biasa dan server memproses
    // daftar yang sama berurutan.
    qsa('form[data-ah-bulk]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) { return; }
            var boxes = qsa('input[type="checkbox"][data-ah-select]:checked', form);
            if (!boxes.length) {
                event.preventDefault();
                AlHasan.toast('Pilih data lebih dahulu.', 'warn');
                return;
            }
            event.preventDefault();
            var field = form.getAttribute('data-ah-bulk-field') || 'id';
            var fixed = {};
            qsa('input[type="hidden"]', form).forEach(function (input) {
                if (input.name && input.name !== '_csrf') { fixed[input.name] = input.value; }
            });
            var holder = doc.getElementById(form.getAttribute('data-ah-bulk-target') || '') || el('div');
            if (!holder.parentNode) { form.parentNode.insertBefore(holder, form); }
            AlHasan.bulk({
                container: holder,
                title: form.getAttribute('data-ah-bulk'),
                items: boxes.map(function (box) { return {id: box.value, label: box.getAttribute('data-ah-label') || box.value}; }),
                run: function (item) {
                    var data = Object.assign({}, fixed);
                    data[field] = item.id;
                    return AlHasan.post(form.action, data);
                },
                onDone: function () {
                    var reload = el('a', 'btn btn-outline-primary', 'Muat ulang daftar');
                    reload.href = window.location.href;
                    var actions = holder.querySelector('.ah-bulk__actions');
                    if (actions) { actions.appendChild(reload); }
                }
            });
        });
    });

    /* --- Redesain Tahap 4: dialog formulir, alasan cepat, pemilih santri -- */
    // Tindakan yang membawa formulir (I-5, J-1): <details data-ah-dialog>.
    // Tanpa JavaScript formulir terbuka di tempat; dengan JavaScript ia
    // dipindah ke <dialog> modal dan dikembalikan saat ditutup, sehingga
    // isian dan validasi HTML tetap milik formulir yang sama.
    qsa('details[data-ah-dialog]').forEach(function (details) {
        var summary = details.querySelector('summary');
        var body = details.querySelector('.ah-act__body');
        if (!summary || !body || typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) { return; }
        var box = null;
        function open() {
            if (!box) {
                box = el('dialog', 'ah-dialog ah-dialog--form');
                var title = body.querySelector('.ah-act__title');
                if (title && title.id) { box.setAttribute('aria-labelledby', title.id); }
                doc.body.appendChild(box);
                box.addEventListener('close', function () {
                    details.appendChild(body);
                    details.open = false;
                    summary.focus();
                });
                box.addEventListener('click', function (event) {
                    if (event.target === box) { box.close(); }
                });
            }
            box.appendChild(body);
            box.showModal();
            var first = body.querySelector('[aria-invalid="true"], textarea, select, input:not([type="hidden"])');
            if (first) { first.focus(); }
        }
        summary.addEventListener('click', function (event) {
            event.preventDefault();
            open();
        });
        qsa('[data-ah-dialog-close]', body).forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (box && box.open) { box.close(); }
            });
        });
        // Setelah validasi server gagal, formulirnya langsung dibuka lagi.
        if (details.open) { details.open = false; open(); }
    });
    // Tautan pemicu tindakan dari tempat lain (bilah bawah HP).
    qsa('[data-ah-open-dialog]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var target = doc.getElementById(link.getAttribute('data-ah-open-dialog'));
            var summary = target && target.querySelector('summary');
            if (summary) { event.preventDefault(); summary.click(); }
        });
    });

    // Alasan cepat (keputusan M): hanya mengisi AWAL teks alasan, yang tetap
    // dapat diubah. Tidak ada isian atau kolom database tambahan.
    qsa('[data-ah-prefix]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = doc.getElementById(button.getAttribute('data-ah-prefix-target'));
            if (!target) { return; }
            var prefixes = qsa('[data-ah-prefix]', button.parentNode).map(function (b) { return b.getAttribute('data-ah-prefix'); });
            var value = target.value;
            prefixes.forEach(function (prefix) {
                if (value.indexOf(prefix) === 0) { value = value.slice(prefix.length); }
            });
            target.value = button.getAttribute('data-ah-prefix') + value.replace(/^\s+/, '');
            qsa('[data-ah-prefix]', button.parentNode).forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
            target.focus();
            target.setSelectionRange(target.value.length, target.value.length);
        });
    });

    // Kotak pilih yang dapat dicari: isian teks menyaring <option>.
    qsa('input[data-ah-option-filter]').forEach(function (input) {
        var select = doc.getElementById(input.getAttribute('data-ah-option-filter'));
        if (!select) { return; }
        input.hidden = false;
        var options = qsa('option', select).filter(function (option) { return option.value !== ''; });
        input.addEventListener('input', function () {
            var term = normal(input.value);
            var shown = 0;
            options.forEach(function (option) {
                var match = term === '' || normal(option.textContent).indexOf(term) !== -1;
                option.hidden = !match;
                option.disabled = !match && !option.selected;
                if (match) { shown++; }
            });
            var status = doc.getElementById(input.getAttribute('aria-describedby') || '');
            if (status) { status.textContent = term === '' ? '' : shown + ' ' + (input.getAttribute('data-ah-option-kind') || 'santri') + ' cocok.'; }
            if (shown === 1 && term !== '') {
                var only = options.filter(function (option) { return !option.hidden; })[0];
                if (select.value !== only.value) { select.value = only.value; select.dispatchEvent(new Event('change')); }
            }
        });
    });

    // Panel "Akan diteruskan ke" (I-4): murobi tujuan, durasi, jumlah izin
    // semester ini. Dibaca dari server yang memeriksa cakupan akun.
    qsa('[data-ah-izin-info]').forEach(function (panel) {
        var form = doc.getElementById(panel.getAttribute('data-ah-izin-info'));
        if (!form) { return; }
        var select = form.querySelector('select[name="santri_id"]');
        var from = form.querySelector('input[name="tgl_izin"]');
        var to = form.querySelector('input[name="tgl_kembali"]');
        function set(key, text) {
            var target = panel.querySelector('[data-ah-info="' + key + '"]');
            if (target) { target.textContent = text; }
        }
        function durasi() {
            if (!from || !to || !from.value || !to.value) { set('durasi', '—'); return; }
            var a = new Date(from.value + 'T00:00:00');
            var b = new Date(to.value + 'T00:00:00');
            var days = Math.round((b - a) / 86400000) + 1;
            set('durasi', days > 0 ? days + ' hari' : 'Tanggal kembali mendahului tanggal izin');
        }
        function santri() {
            if (!select || !select.value) {
                set('murobi', 'Pilih santri lebih dahulu');
                set('jumlah', '—');
                return;
            }
            set('murobi', 'Memuat…');
            var url = panel.getAttribute('data-ah-izin-url') + (panel.getAttribute('data-ah-izin-url').indexOf('?') === -1 ? '?' : '&')
                + 'santri_id=' + encodeURIComponent(select.value);
            fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (response) { return response.json().then(function (json) { return {ok: response.ok, json: json}; }); })
                .then(function (result) {
                    if (!result.ok || !result.json || result.json.ok === false) { throw new Error((result.json && result.json.message) || 'Gagal'); }
                    set('murobi', result.json.murobi);
                    set('jumlah', result.json.jumlah);
                })
                .catch(function () {
                    set('murobi', 'Belum dapat dibaca; server tetap menentukan saat dikirim');
                    set('jumlah', '—');
                });
        }
        if (select) { select.addEventListener('change', santri); }
        if (from) { from.addEventListener('change', durasi); }
        if (to) { to.addEventListener('change', durasi); }
        durasi();
        if (select && select.value && panel.getAttribute('data-ah-izin-loaded') !== '1') { santri(); }
    });

    // Penyaring daftar di halaman (J-2: cari santri peserta).
    qsa('input[data-ah-list-filter]').forEach(function (input) {
        var list = doc.getElementById(input.getAttribute('data-ah-list-filter'));
        if (!list) { return; }
        input.closest('[hidden]') && (input.closest('[hidden]').hidden = false);
        input.addEventListener('input', function () {
            var term = normal(input.value);
            qsa('[data-ah-filter-text]', list).forEach(function (item) {
                item.hidden = term !== '' && normal(item.getAttribute('data-ah-filter-text')).indexOf(term) === -1;
            });
        });
    });

    // Tombol cetak halaman (I-5, J-5).
    qsa('[data-ah-print]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () { window.print(); });
    });

    /* --- Redesain Tahap 5: pembinaan ------------------------------------ */
    // Isian yang hanya relevan untuk pilihan tertentu (V-4: hasil sesi).
    // <div data-ah-when="nama_select" data-ah-when-value="Selesai|Tidak Hadir">.
    // Tanpa JavaScript semua isian tampil dengan keterangan kapan dipakai;
    // server tetap memvalidasi isian yang wajib untuk setiap hasil.
    qsa('[data-ah-when]').forEach(function (block) {
        var form = block.closest('form');
        var select = form && form.querySelector('[name="' + block.getAttribute('data-ah-when') + '"]');
        if (!select) { return; }
        var values = block.getAttribute('data-ah-when-value').split('|');
        function sync() {
            var show = values.indexOf(select.value) !== -1;
            block.hidden = !show;
            qsa('input, textarea, select', block).forEach(function (field) { field.disabled = !show; });
        }
        select.addEventListener('change', sync);
        sync();
    });

    // Pratinjau poin (V-1): jenis menampilkan tingkat dan poin; total poin
    // santri setelah catatan ini dihitung dari angka yang dikirim server.
    qsa('[data-ah-poin-preview]').forEach(function (hint) {
        var form = hint.closest('form');
        var santri = form && form.querySelector('select[name="santri_scope"]');
        var jenis = form && form.querySelector('select[name="katalog_id"]');
        if (!santri || !jenis) { return; }
        function sync() {
            var s = santri.options[santri.selectedIndex];
            var j = jenis.options[jenis.selectedIndex];
            var poin = j && j.value ? parseInt(j.getAttribute('data-poin') || '0', 10) : null;
            var total = s && s.value ? parseInt(s.getAttribute('data-total') || '0', 10) : null;
            if (poin === null) { hint.textContent = 'Pilih jenis untuk melihat tingkat dan poinnya.'; return; }
            var text = (j.getAttribute('data-tingkat') || '') + ' · ' + poin + ' poin.';
            if (total !== null) { text += ' Total poin santri setelah catatan ini: ' + (total + poin) + '.'; }
            hint.textContent = text;
        }
        santri.addEventListener('change', sync);
        jenis.addEventListener('change', sync);
        hint.hidden = false;
        sync();
    });

    if (window.jQuery) {
        window.jQuery.ajaxSetup({headers: {'X-CSRF-Token': window.ALHASAN_CSRF}});
    }
})();
