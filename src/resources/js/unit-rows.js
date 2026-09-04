document.addEventListener('DOMContentLoaded', function () {
    function toggleEmptyHint(section) {
        if (!section) return;
        const hint = section.querySelector('[data-unit-empty-hint]');
        const rows = section.querySelectorAll('[data-unit-row]');
        if (hint) hint.classList.toggle('hidden', rows.length > 0);
    }

    function formatNumber(n) {
        if (!isFinite(n)) return '0';
        return String(Math.round(n * 1000) / 1000);
    }

    /**
     * Baris PALING BAWAH di tabel = Satuan Dasar (posisional, bukan lewat
     * radio — lihat catatan panjang di ProductController::extractUnits()).
     * Fungsi ini menyamakan tampilan setiap baris dengan posisinya saat ini:
     * - Baris terakhir: sembunyikan input "Isi", tampilkan badge "Satuan Dasar".
     * - Baris lainnya: tampilkan input "Isi" (wajib diisi).
     * Sekaligus menghitung ulang pratinjau "= X satuan dasar" berjenjang dari
     * bawah (dasar = 1) naik ke atas, supaya user langsung lihat hasil akhir
     * konversinya tanpa harus submit dulu.
     */
    function refreshUnitRows(section) {
        if (!section) return;
        const rows = Array.from(section.querySelectorAll('[data-unit-row]'));
        const lastIndex = rows.length - 1;

        let cumulative = 1;
        let baseUnitName = '';

        rows.forEach(function (row, i) {
            const isLast = i === lastIndex;
            const relativeInput = row.querySelector('[data-field="relative_qty"], input[name$="[relative_qty]"]');
            const baseLabel = row.querySelector('[data-base-label]');
            const hintEl = row.querySelector('[data-conversion-hint]');
            const nameInput = row.querySelector('[data-field="unit_name"], input[name$="[unit_name]"]');
            const unitName = nameInput ? (nameInput.value || '').trim() : '';

            if (relativeInput) {
                relativeInput.classList.toggle('hidden', isLast);
                if (isLast) {
                    relativeInput.removeAttribute('required');
                } else {
                    relativeInput.setAttribute('required', 'required');
                }
            }
            if (baseLabel) baseLabel.classList.toggle('hidden', !isLast);

            if (isLast) {
                cumulative = 1;
                baseUnitName = unitName || 'satuan dasar';
                if (hintEl) hintEl.textContent = '';
                return;
            }

            const qty = relativeInput ? parseFloat(relativeInput.value || '0') : 0;
            if (qty > 0) {
                cumulative = qty * cumulative;
                if (hintEl) hintEl.textContent = '= ' + formatNumber(cumulative) + ' ' + baseUnitName;
            } else if (hintEl) {
                hintEl.textContent = '';
            }
        });
    }

    function bindRowEvents(section) {
        if (!section) return;

        section.querySelectorAll('[data-unit-remove]').forEach(function (btn) {
            btn.onclick = function () {
                const row = btn.closest('[data-unit-row]');
                if (row) row.remove();
                toggleEmptyHint(section);
                refreshUnitRows(section);
            };
        });

        section.querySelectorAll('[data-field="relative_qty"], [data-field="unit_name"], input[name$="[relative_qty]"], input[name$="[unit_name]"]').forEach(function (input) {
            input.oninput = function () {
                refreshUnitRows(section);
            };
        });
    }

    function addUnitRow(formId, prefill) {
        prefill = prefill || {};
        const container = document.querySelector('[data-unit-rows="' + formId + '"]');
        const template = document.querySelector('[data-unit-row-template="' + formId + '"]');
        if (!container || !template) return;

        let counter = parseInt(container.getAttribute('data-counter') || '0', 10);
        const index = counter;
        container.setAttribute('data-counter', String(counter + 1));

        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-unit-row]');

        row.querySelectorAll('[data-field]').forEach(function (el) {
            const field = el.getAttribute('data-field');

            if (field === 'is_purchase_unit') {
                el.name = 'is_purchase_unit_index_' + formId;
                el.value = String(index);
            } else {
                el.name = 'units[' + index + '][' + field + ']';
            }

            if (prefill[field] !== undefined) {
                if (el.type === 'radio' || el.type === 'checkbox') {
                    el.checked = !!prefill[field];
                } else {
                    el.value = prefill[field];
                }
            }
        });

        // PENTING: selalu sisipkan baris baru SEBELUM baris paling bawah yang
        // sudah ada (kalau ada) — bukan appendChild ke paling bawah. Ini
        // menjaga baris paling bawah (Satuan Dasar) tetap di posisinya,
        // konsisten dengan aturan di server: "baris terakhir = satuan dasar".
        const existingRows = container.querySelectorAll('[data-unit-row]');
        if (existingRows.length > 0) {
            container.insertBefore(row, existingRows[existingRows.length - 1]);
        } else {
            container.appendChild(row);
        }

        const section = container.closest('[data-unit-section]');
        bindRowEvents(section);
        toggleEmptyHint(section);
        refreshUnitRows(section);
    }

    document.querySelectorAll('[data-unit-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addUnitRow(btn.getAttribute('data-unit-add'));
        });
    });

    document.querySelectorAll('[data-unit-section]').forEach(function (section) {
        bindRowEvents(section);
        toggleEmptyHint(section);
        refreshUnitRows(section);
    });

    document.querySelectorAll('[data-tracking-mode-radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const formId = radio.getAttribute('data-tracking-mode-radio');
            const fractionalCheckbox = document.querySelector('[data-fractional-checkbox="' + formId + '"]');
            const container = document.querySelector('[data-unit-rows="' + formId + '"]');

            if (radio.value === 'weight') {
                if (fractionalCheckbox) fractionalCheckbox.checked = true;

                // "gram" ditambah LEBIH DULU (jadi baris pertama & otomatis baris
                // paling bawah = Satuan Dasar), baru "kg" ditambah setelahnya —
                // karena addUnitRow() selalu menyisipkan baris baru DI ATAS baris
                // paling bawah yang sudah ada, urutan akhirnya kg (atas) - gram (bawah).
                if (container && container.children.length === 0) {
                    addUnitRow(formId, { unit_name: 'gram' });
                    addUnitRow(formId, { unit_name: 'kg', relative_qty: 1000, is_purchase_unit: true });
                }
            } else if (fractionalCheckbox) {
                fractionalCheckbox.checked = false;
            }
        });
    });
});
