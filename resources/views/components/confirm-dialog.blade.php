{{-- Dialog konfirmasi reusable pengganti confirm() bawaan browser.
     Dipakai via JS global: showConfirm({title, message, confirmLabel, cancelLabel, danger})
     atau handleConfirm(event, {...}) untuk tombol/form. Satu instance dipakai ulang. --}}
<div class="confirm-overlay" id="confirmOverlay" hidden>
    <div class="confirm-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
        <div class="confirm-icon" id="confirmIcon" aria-hidden="true"></div>
        <h3 class="confirm-title" id="confirmTitle"></h3>
        <p class="confirm-message" id="confirmMessage"></p>
        <div class="confirm-actions">
            <button type="button" class="button confirm-cancel" id="confirmCancel">Batal</button>
            <button type="button" class="button confirm-ok" id="confirmOk">Ya, lanjutkan</button>
        </div>
    </div>
</div>
