document.addEventListener('DOMContentLoaded', function () {
    function getProducts(formId) {
        var el = document.querySelector('[data-products-data="' + formId + '"]');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return [];
        }
    }

    function formatRupiah(n) {
        return 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
    }

    function fillUnitOptions(unitSelect, products, productId) {
        var product = products.find(function (p) { return String(p.id) === String(productId); });
        unitSelect.innerHTML = '';

        if (!product || !product.units || product.units.length === 0) {
            unitSelect.innerHTML = '<option value="">-- produk belum punya satuan --</option>';
            return;
        }

        var defaultUnit = product.units.find(function (u) { return u.is_purchase_unit; }) || product.units[0];
        product.units.forEach(function (u) {
            var opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.unit_name;
            if (defaultUnit && String(defaultUnit.id) === String(u.id)) opt.selected = true;
            unitSelect.appendChild(opt);
        });
    }

    function recalcGrandTotal(formId) {
        var container = document.querySelector('[data-item-rows="' + formId + '"]');
        var totalEl = document.querySelector('[data-grand-total="' + formId + '"]');
        if (!container || !totalEl) return;

        var total = 0;
        container.querySelectorAll('[data-item-row]').forEach(function (row) {
            var qty = parseFloat(row.querySelector('[data-field="quantity_ordered"]')?.value || 0);
            var price = parseFloat(row.querySelector('[data-field="unit_price"]')?.value || 0);
            var subtotal = qty * price;

            var subtotalEl = row.querySelector('[data-row-subtotal]');
            if (subtotalEl) subtotalEl.textContent = formatRupiah(subtotal);

            total += subtotal;
        });

        totalEl.textContent = formatRupiah(total);
    }

    function toggleEmptyHint(section) {
        if (!section) return;
        var hint = section.querySelector('[data-item-empty-hint]');
        var rows = section.querySelectorAll('[data-item-row]');
        if (hint) hint.classList.toggle('hidden', rows.length > 0);
    }

    function bindRow(row, formId, products) {
        var productSelect = row.querySelector('[data-field="product_id"]');
        var unitSelect = row.querySelector('[data-field="product_unit_id"]');

        if (productSelect && unitSelect) {
            productSelect.addEventListener('change', function () {
                fillUnitOptions(unitSelect, products, productSelect.value);
                recalcGrandTotal(formId);
            });
        }

        row.querySelectorAll('[data-field="quantity_ordered"], [data-field="unit_price"]').forEach(function (el) {
            el.addEventListener('input', function () { recalcGrandTotal(formId); });
        });

        var removeBtn = row.querySelector('[data-item-remove]');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                var section = row.closest('[data-item-section]');
                row.remove();
                recalcGrandTotal(formId);
                toggleEmptyHint(section);
            });
        }
    }

    function addItemRow(formId) {
        var container = document.querySelector('[data-item-rows="' + formId + '"]');
        var template = document.querySelector('[data-item-row-template="' + formId + '"]');
        if (!container || !template) return;

        var counter = parseInt(container.getAttribute('data-counter') || '0', 10);
        container.setAttribute('data-counter', String(counter + 1));

        var fragment = template.content.cloneNode(true);
        var row = fragment.querySelector('[data-item-row]');

        row.querySelectorAll('[data-field]').forEach(function (el) {
            var field = el.getAttribute('data-field');
            el.name = 'items[' + counter + '][' + field + ']';
        });

        container.appendChild(row);
        bindRow(row, formId, getProducts(formId));
        toggleEmptyHint(container.closest('[data-item-section]'));
    }

    document.querySelectorAll('[data-item-add]').forEach(function (btn) {
        var formId = btn.getAttribute('data-item-add');
        btn.addEventListener('click', function () { addItemRow(formId); });
    });

    document.querySelectorAll('[data-item-section]').forEach(function (section) {
        var container = section.querySelector('[data-item-rows]');
        if (!container) return;
        var formId = container.getAttribute('data-item-rows');
        var products = getProducts(formId);

        container.querySelectorAll('[data-item-row]').forEach(function (row) {
            bindRow(row, formId, products);
        });

        recalcGrandTotal(formId);
        toggleEmptyHint(section);
    });
});
