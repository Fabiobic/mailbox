/* =============================================
   Mailbox - PST Email Manager
   Main JavaScript
   ============================================= */

'use strict';

document.addEventListener('DOMContentLoaded', function () {

    // ── Auto-submit filtri su cambio select ────────────────────────────
    const autoSubmitSelects = document.querySelectorAll(
        '#filterForm select[name="folder"], #filterForm select[name="import_id"], #filterForm select[name="sort"]'
    );
    autoSubmitSelects.forEach(function (el) {
        el.addEventListener('change', function () {
            document.getElementById('filterForm')?.submit();
        });
    });

    // ── Conferma eliminazione ──────────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm || 'Sei sicuro?')) {
                e.preventDefault();
            }
        });
    });

    // ── Tooltip Bootstrap ─────────────────────────────────────────────
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // ── Upload progress (aggiornamento visivo) ────────────────────────
    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function () {
            const bar = document.getElementById('uploadBar');
            const progress = document.getElementById('uploadProgress');
            if (progress) progress.classList.remove('d-none');
            // Simula avanzamento per feedback visivo
            let pct = 0;
            const timer = setInterval(function () {
                pct = Math.min(pct + Math.random() * 8, 90);
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.textContent = Math.round(pct) + '%';
                }
            }, 400);
        });
    }

    // ── Highlight riga email in lista ─────────────────────────────────
    document.querySelectorAll('.table tbody tr').forEach(function (row) {
        row.style.cursor = 'pointer';
        const link = row.querySelector('a[href*="email.php"]');
        if (link) {
            row.addEventListener('click', function (e) {
                if (!e.target.closest('a') || e.target.closest('a') === link) {
                    window.location.href = link.href;
                }
            });
        }
    });

    // ── Pulizia form filtri con tasto ESC ─────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const inputs = document.querySelectorAll('#filterForm input[type="text"]');
            let hasValue = false;
            inputs.forEach(function (i) { if (i.value) hasValue = true; });
            if (hasValue) {
                if (confirm('Azzerare tutti i filtri?')) {
                    window.location.href = 'index.php';
                }
            }
        }
    });

});
