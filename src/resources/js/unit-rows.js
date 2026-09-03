document.addEventListener('DOMContentLoaded', function () {
    function toggleEmptyHint(section) {
        if (!section) return;
        const hint = section.querySelector('[data-unit-empty-hint]');
        const rows = section.querySelectorAll('[data-unit-row]');
        if (hint) hint.classList.toggle('hidden', rows.length > 0);
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

            if (field === 'is_base_unit') {
                el.name = 'is_base_unit_index_' + formId;
                el.value = String(index);
            } else if (field === 'is_purchase_unit') {
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

        container.appendChild(row);
        bindRowEvents(container.closest('[data-unit-section]'));
        toggleEmptyHint(container.closest('[data-unit-section]'));

        // Kalau baris baru langsung diprefill sebagai satuan dasar (dipakai
        // saat auto-isi kg/gram untuk mode "Timbang"), kunci konversinya juga.
        if (prefill.is_base_unit) {
            const baseRadio = row.querySelector('input[name^="is_base_unit_index_"]');
            if (baseRadio) baseRadio.dispatchEvent(new Event('change'));
        }
    }

    function bindRowEvents(section) {
        if (!section) return;

        // Tombol hapus baris
        section.querySelectorAll('[data-unit-remove]').forEach(function (btn) {
            btn.onclick = function () {
                const row = btn.closest('[data-unit-row]');
                if (row) row.remove();
                toggleEmptyHint(section);
            };
        });

        // Saat radio "Jadikan Satuan Dasar" dicentang: kunci konversi row itu ke 1
        section.querySelectorAll('input[name^="is_base_unit_index_"]').forEach(function (radio) {
            radio.onchange = function () {
                section.querySelectorAll('[data-unit-row]').forEach(function (row) {
                    const conversionInput = row.querySelector('[data-field="conversion_to_base"], input[name$="[conversion_to_base]"]');
                    const baseRadio = row.querySelector('input[name^="is_base_unit_index_"]');
                    if (!conversionInput || !baseRadio) return;

                    if (baseRadio.checked) {
                        conversionInput.value = '1';
                        conversionInput.readOnly = true;
                        conversionInput.classList.add('bg-gray-50');
                    } else {
                        conversionInput.readOnly = false;
                        conversionInput.classList.remove('bg-gray-50');
                    }
                });
            };
        });
    }

    // Tombol "+ Tambah Satuan"
    document.querySelectorAll('[data-unit-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addUnitRow(btn.getAttribute('data-unit-add'));
        });
    });

    // Inisialisasi event + status hint kosong untuk baris yang sudah ada
    // duluan (mode edit produk existing, atau hasil restore old('units')
    // setelah validasi gagal).
    document.querySelectorAll('[data-unit-section]').forEach(function (section) {
        bindRowEvents(section);
        toggleEmptyHint(section);
    });

    // Toggle tracking_mode: Unit vs Timbang
    document.querySelectorAll('[data-tracking-mode-radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const formId = radio.getAttribute('data-tracking-mode-radio');
            const fractionalCheckbox = document.querySelector('[data-fractional-checkbox="' + formId + '"]');
            const container = document.querySelector('[data-unit-rows="' + formId + '"]');

            if (radio.value === 'weight') {
                if (fractionalCheckbox) fractionalCheckbox.checked = true;

                // Auto-isi baris starter kg & gram HANYA kalau tabel masih kosong
                if (container && container.children.length === 0) {
                    addUnitRow(formId, { unit_name: 'kg', conversion_to_base: 1000, is_purchase_unit: true });
                    addUnitRow(formId, { unit_name: 'gram', conversion_to_base: 1, is_base_unit: true });
                }
            } else if (fractionalCheckbox) {
                fractionalCheckbox.checked = false;
            }
        });
    });
});
