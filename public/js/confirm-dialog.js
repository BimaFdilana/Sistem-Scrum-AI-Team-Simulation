/* Dialog konfirmasi reusable — pengganti confirm() bawaan browser.
 *
 * Cara pakai di Blade:
 *   <button ... onclick="return handleConfirm(event, {title: '...', message: '...', danger: true})">
 *   <form ... onsubmit="return handleConfirm(event, {title: '...', message: '...'})">
 *
 * Atau langsung: showConfirm({title, message, confirmLabel, cancelLabel, danger})
 *   → Promise<boolean> (true bila user tekan tombol aksi)
 */
(function () {
    'use strict';

    var overlay, card, iconEl, titleEl, messageEl, okBtn, cancelBtn;
    var pendingResolve = null;
    var lastTrigger = null;

    function els() {
        if (overlay) return true;
        overlay = document.getElementById('confirmOverlay');
        if (!overlay) return false;
        card = overlay.querySelector('.confirm-card');
        iconEl = document.getElementById('confirmIcon');
        titleEl = document.getElementById('confirmTitle');
        messageEl = document.getElementById('confirmMessage');
        okBtn = document.getElementById('confirmOk');
        cancelBtn = document.getElementById('confirmCancel');

        okBtn.addEventListener('click', function () { close(true); });
        cancelBtn.addEventListener('click', function () { close(false); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && pendingResolve) close(false);
        });
        return true;
    }

    function open(opts) {
        if (!els()) return Promise.resolve(false);
        // Abaikan bila dialog sedang terbuka (hindari tumpukan).
        if (pendingResolve) return Promise.resolve(false);

        opts = opts || {};
        titleEl.textContent = opts.title || 'Konfirmasi';
        messageEl.textContent = opts.message || 'Lanjutkan tindakan ini?';
        okBtn.textContent = opts.confirmLabel || 'Ya, lanjutkan';
        cancelBtn.textContent = opts.cancelLabel || 'Batal';
        okBtn.classList.toggle('button-danger', !!opts.danger);
        okBtn.classList.toggle('button-primary', !opts.danger);
        iconEl.textContent = opts.danger ? '!' : '?';
        iconEl.classList.toggle('is-danger', !!opts.danger);

        lastTrigger = document.activeElement;
        overlay.hidden = false;
        // Paksa reflow agar animasi berjalan tiap dibuka.
        void card.offsetWidth;
        overlay.classList.add('is-open');
        cancelBtn.focus();

        return new Promise(function (resolve) {
            pendingResolve = resolve;
        });
    }

    function close(result) {
        if (!pendingResolve) return;
        var resolve = pendingResolve;
        pendingResolve = null;
        overlay.classList.remove('is-open');
        // Tunggu animasi keluar selesai sebelum disembunyikan.
        setTimeout(function () {
            overlay.hidden = true;
            if (lastTrigger && typeof lastTrigger.focus === 'function') {
                try { lastTrigger.focus(); } catch (e) { /* abaikan */ }
            }
        }, 160);
        resolve(result);
    }

    window.showConfirm = open;

    /**
     * Pengganti drop-in untuk onclick="return confirm('...')" / onsubmit="return confirm('...')".
     * Selalu return false (cegah submit bawaan); bila user setuju, form di-submit
     * ulang secara programatik — via requestSubmit(submitter) agar name/value
     * tombol ikut terkirim, atau form.submit() untuk form tanpa submitter bernama.
     */
    window.handleConfirm = function (event, opts) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();

        var target = event ? event.target : null;
        var form = null;
        var submitter = null;
        if (target) {
            if (target.tagName === 'FORM') {
                form = target;
            } else {
                submitter = target.closest('button, input[type="submit"]') || target;
                form = submitter.closest ? submitter.closest('form') : null;
            }
        }

        open(opts).then(function (ok) {
            if (!ok || !form) return;
            if (submitter && typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter);
            } else {
                form.submit();
            }
        });

        return false;
    };
})();
