import { Html5Qrcode } from 'html5-qrcode';

/**
 * Modul Kasir (POS) — Tahap 1: pencarian produk (nama/SKU/scan) & keranjang
 * di sisi client. Belum ada checkout/pembayaran (Tahap 3), jadi state
 * keranjang cukup disimpan di memori JS, tidak perlu ke server dulu.
 *
 * Semua elemen yang tampil di 2 tempat (panel keranjang desktop & overlay
 * mobile) di-query pakai class lewat querySelectorAll, BUKAN getElementById
 * — lihat catatan di kasir/partials/cart-panel.blade.php.
 */
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('kasir-pos-root');
    if (!root) return; // halaman lain, tidak relevan

    const searchUrl = root.dataset.searchUrl;
    const barcodeUrlBase = root.dataset.barcodeUrl;
    const checkoutUrl = root.dataset.checkoutUrl;
    const strukUrlBase = root.dataset.strukUrlBase;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    const searchInput = document.getElementById('pos-search-input');
    const resultsContainer = document.getElementById('pos-search-results');
    const emptyState = document.getElementById('pos-empty-state');
    const inlineMessage = document.getElementById('pos-inline-message');

    /** @type {Array<{lineId:string, productId:number, productName:string, unitId:number, unitName:string, price:number, qty:number, fractional:boolean}>} */
    let cart = [];
    let cartLineSeq = 0;
    let hasStockIssue = false;

    // ===================== Helper umum =====================

    function formatRupiah(n) {
        n = Math.round(Number(n) || 0);
        return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function formatQty(n) {
        return parseFloat((Math.round(Number(n) * 1000) / 1000).toFixed(3)).toString();
    }

    function debounce(fn, delay) {
        let t = null;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function showInlineMessage(message, isError) {
        inlineMessage.textContent = message;
        inlineMessage.className = 'mt-2 text-xs font-medium ' + (isError ? 'text-red-600' : 'text-green-600');
        inlineMessage.classList.remove('hidden');
        clearTimeout(showInlineMessage._t);
        showInlineMessage._t = setTimeout(() => inlineMessage.classList.add('hidden'), 3000);
    }

    function baseUnitLabel(product) {
        const base = product.units.find((u) => u.is_base_unit);
        return base ? base.unit_name : '';
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        let body = null;
        try {
            body = await res.json();
        } catch (e) {
            // respons bukan JSON (mis. redirect ke halaman login karena sesi habis)
        }
        return { ok: res.ok, status: res.status, body };
    }

    // ===================== Keranjang =====================

    function addToCart(product, unit, qty = 1) {
        const existing = cart.find((l) => l.productId === product.id && l.unitId === unit.id);

        // Refresh info stok/konversi ke nilai TERBARU tiap kali produk ini
        // ditambahkan lagi (mis. discan ulang), bukan cuma dipakai sekali di
        // awal — supaya validasi stok di renderCart() tidak pakai data basi
        // kalau ada penambahan stok/pengurangan di sesi kasir lain.
        const baseUnit = product.units.find((u) => u.is_base_unit);

        if (existing) {
            existing.qty = round3(existing.qty + qty);
            existing.stock = product.stock;
        } else {
            cart.push({
                lineId: 'line-' + (++cartLineSeq),
                productId: product.id,
                productName: product.name,
                unitId: unit.id,
                unitName: unit.unit_name,
                price: unit.selling_price,
                qty,
                fractional: !!product.allow_fractional_sale,
                conversionToBase: Number(unit.conversion_to_base) || 1,
                stock: product.stock,
                baseUnitName: baseUnit ? baseUnit.unit_name : '',
                discountType: null, // null | 'nominal' | 'percent'
                discountValue: 0,
            });
        }

        renderCart();
    }

    function round3(n) {
        return Math.round(n * 1000) / 1000;
    }

    function removeLine(lineId) {
        cart = cart.filter((l) => l.lineId !== lineId);
        renderCart();
    }

    function changeLineQty(lineId, newQty) {
        const line = cart.find((l) => l.lineId === lineId);
        if (!line) return;

        const step = line.fractional ? 0.001 : 1;
        newQty = Math.max(step, round3(newQty));
        line.qty = newQty;
        renderCart();
    }

    /**
     * Hitung subtotal 1 baris keranjang dengan diskonnya. Diskon persen
     * dihitung dari (harga x qty), diskon nominal di-cap supaya subtotal
     * tidak pernah negatif (mis. qty dikurangi belakangan sampai lebih kecil
     * dari nominal diskon yang sudah diset).
     */
    function computeLineAmounts(line) {
        const gross = line.price * line.qty;
        let discountAmount = 0;

        if (line.discountType === 'percent') {
            discountAmount = Math.round(gross * (Math.min(100, Math.max(0, line.discountValue)) / 100));
        } else if (line.discountType === 'nominal') {
            discountAmount = Math.round(Math.max(0, line.discountValue));
        }
        discountAmount = Math.min(discountAmount, gross);

        return { gross, discountAmount, net: gross - discountAmount };
    }

    /**
     * Agregat total kebutuhan per PRODUK (bukan per baris) — 1 produk bisa
     * ada di beberapa baris keranjang dengan satuan berbeda (mis. 2 dus + 5
     * sachet lepas), tapi stoknya cuma satu, jadi harus dijumlah dalam
     * satuan dasar dulu sebelum dibandingkan ke `stock`.
     */
    function productStockAggregate() {
        const map = {};
        cart.forEach((l) => {
            if (!map[l.productId]) {
                map[l.productId] = { totalBase: 0, stock: l.stock, baseUnitName: l.baseUnitName };
            }
            map[l.productId].totalBase = round3(map[l.productId].totalBase + l.qty * l.conversionToBase);
            map[l.productId].stock = l.stock; // selalu pakai info stok TERBARU dari baris manapun
        });
        return map;
    }

    function cartRowHtml(line, stockInfo) {
        const step = line.fractional ? '0.001' : '1';
        const { gross, discountAmount, net } = computeLineAmounts(line);
        const exceeds = stockInfo && round3(stockInfo.totalBase) > round3(stockInfo.stock) + 0.0005;

        const discountBadge = line.discountType
            ? `<button type="button" data-cart-discount="${line.lineId}" class="text-xs text-amber-600 font-medium hover:underline">
                   ${line.discountType === 'percent' ? '-' + formatQty(line.discountValue) + '%' : '-' + formatRupiah(discountAmount)}
               </button>`
            : `<button type="button" data-cart-discount="${line.lineId}" class="text-xs text-indigo-500 hover:underline">+ Diskon</button>`;

        const warningHtml = exceeds
            ? `<p class="mt-1 text-xs text-red-600 font-medium">⚠ Stok tidak cukup — butuh ${formatQty(stockInfo.totalBase)}, tersedia ${formatQty(stockInfo.stock)} ${escapeHtml(stockInfo.baseUnitName)}</p>`
            : '';

        return `
            <div class="flex items-start gap-3 px-4 py-3 ${exceeds ? 'bg-red-50' : ''}" data-cart-row="${line.lineId}">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(line.productName)}</p>
                    <p class="text-xs text-gray-400">${escapeHtml(line.unitName)} &middot; ${formatRupiah(line.price)}</p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <button type="button" data-cart-dec="${line.lineId}"
                                style="width:1.75rem;height:1.75rem;" class="w-7 h-7 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 text-base leading-none">&minus;</button>
                        <input type="number" step="${step}" min="${step}" value="${formatQty(line.qty)}"
                               data-cart-qty-input="${line.lineId}"
                               style="width:4rem;" class="w-16 text-center rounded-md text-sm py-1 ${exceeds ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-300'}">
                        <button type="button" data-cart-inc="${line.lineId}"
                                style="width:1.75rem;height:1.75rem;" class="w-7 h-7 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 text-base leading-none">&plus;</button>
                        <span class="mx-1 text-gray-200">|</span>
                        ${discountBadge}
                    </div>
                    ${warningHtml}
                </div>
                <div class="text-right shrink-0">
                    ${discountAmount > 0 ? `<p class="text-xs text-gray-400 line-through">${formatRupiah(gross)}</p>` : ''}
                    <p class="text-sm font-semibold text-gray-900">${formatRupiah(net)}</p>
                    <button type="button" data-cart-remove="${line.lineId}" title="Hapus dari keranjang"
                            style="width:1.75rem;height:1.75rem;"
                            class="mt-2 w-7 h-7 inline-flex items-center justify-center rounded-md border border-gray-300 bg-white text-gray-500 hover:bg-red-50 hover:border-red-300 hover:text-red-600 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </button>
                </div>
            </div>`;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    /** Dipakai bareng oleh renderCart() dan modal pembayaran, supaya angka yang tampil di keranjang dan yang dikirim ke checkout selalu konsisten. */
    function computeCartTotals() {
        let grossTotal = 0;
        let discountTotal = 0;
        cart.forEach((l) => {
            const { gross, discountAmount } = computeLineAmounts(l);
            grossTotal += gross;
            discountTotal += discountAmount;
        });
        return {
            itemCount: cart.reduce((sum, l) => sum + l.qty, 0),
            grossTotal,
            discountTotal,
            grandTotal: grossTotal - discountTotal,
        };
    }

    function renderCart() {
        const containers = document.querySelectorAll('.cart-items-container');
        const stockMap = productStockAggregate();

        const html = cart.length
            ? cart.map((line) => cartRowHtml(line, stockMap[line.productId])).join('')
            : '<p class="cart-empty-state px-4 py-10 text-center text-sm text-gray-400">Keranjang masih kosong.<br>Cari atau scan produk untuk mulai.</p>';

        containers.forEach((el) => { el.innerHTML = html; });

        const { itemCount, grossTotal, discountTotal, grandTotal } = computeCartTotals();
        hasStockIssue = Object.values(stockMap).some((s) => round3(s.totalBase) > round3(s.stock) + 0.0005);

        document.querySelectorAll('.cart-item-count').forEach((el) => { el.textContent = formatQty(itemCount); });
        document.querySelectorAll('.cart-subtotal-display').forEach((el) => { el.textContent = formatRupiah(grossTotal); });
        document.querySelectorAll('.cart-total-display').forEach((el) => { el.textContent = formatRupiah(grandTotal); });

        document.querySelectorAll('.cart-discount-row').forEach((el) => {
            el.classList.toggle('hidden', discountTotal <= 0);
            el.classList.toggle('flex', discountTotal > 0);
        });
        document.querySelectorAll('.cart-discount-display').forEach((el) => { el.textContent = '-' + formatRupiah(discountTotal); });

        document.querySelectorAll('.cart-stock-warning').forEach((el) => {
            el.classList.toggle('hidden', !hasStockIssue);
        });

        document.querySelectorAll('.cart-checkout-btn').forEach((btn) => {
            const disable = cart.length === 0 || hasStockIssue;
            btn.disabled = disable;
            btn.classList.toggle('bg-gray-200', disable);
            btn.classList.toggle('text-gray-400', disable);
            btn.classList.toggle('cursor-not-allowed', disable);
            btn.classList.toggle('bg-indigo-600', !disable);
            btn.classList.toggle('text-white', !disable);
            btn.classList.toggle('hover:bg-indigo-700', !disable);
        });

        const mobileBtn = document.getElementById('pos-mobile-cart-btn');
        if (mobileBtn) {
            mobileBtn.classList.toggle('hidden', cart.length === 0);
            mobileBtn.classList.toggle('flex', cart.length > 0);
        }
    }

    // Delegasi klik untuk tombol di dalam baris keranjang (berlaku utk kedua panel)
    document.addEventListener('click', function (e) {
        const inc = e.target.closest('[data-cart-inc]');
        const dec = e.target.closest('[data-cart-dec]');
        const rem = e.target.closest('[data-cart-remove]');
        const disc = e.target.closest('[data-cart-discount]');

        if (inc) {
            const line = cart.find((l) => l.lineId === inc.getAttribute('data-cart-inc'));
            if (line) changeLineQty(line.lineId, line.qty + (line.fractional ? 0.1 : 1));
        } else if (dec) {
            const line = cart.find((l) => l.lineId === dec.getAttribute('data-cart-dec'));
            if (line) changeLineQty(line.lineId, line.qty - (line.fractional ? 0.1 : 1));
        } else if (rem) {
            removeLine(rem.getAttribute('data-cart-remove'));
        } else if (disc) {
            openDiscountEditor(disc.getAttribute('data-cart-discount'));
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-cart-qty-input]')) {
            const lineId = e.target.getAttribute('data-cart-qty-input');
            const val = parseFloat(e.target.value.replace(',', '.'));
            changeLineQty(lineId, isNaN(val) ? 1 : val);
        }
    });

    // Buka/tutup overlay keranjang mobile
    document.getElementById('pos-mobile-cart-btn')?.addEventListener('click', function () {
        document.getElementById('pos-mobile-cart-overlay').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    });
    document.querySelectorAll('[data-pos-cart-close]').forEach((btn) => {
        btn.addEventListener('click', function () {
            document.getElementById('pos-mobile-cart-overlay').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
    });

    // ===================== Checkout & Pembayaran =====================

    document.querySelectorAll('.cart-checkout-btn').forEach((btn) => {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            openPaymentModal();
        });
    });

    function openPaymentModal() {
        const totals = computeCartTotals();
        renderPaymentContent(totals, 'cash', totals.grandTotal);
        openModalByName('pos-payment');
    }

    function renderPaymentContent(totals, selectedMethod, paidAmount) {
        const content = document.getElementById('pos-payment-content');
        const isCash = selectedMethod === 'cash';
        const insufficientCash = isCash && paidAmount < totals.grandTotal;

        const methods = [
            { key: 'cash', label: 'Cash' },
            { key: 'debit', label: 'Debit' },
            { key: 'qris', label: 'QRIS' },
            { key: 'transfer', label: 'Transfer' },
        ];

        content.innerHTML = `
            <h3 class="font-medium text-gray-900 mb-3">Pembayaran</h3>

            <div class="rounded-md bg-gray-50 p-3 text-sm space-y-1 mb-4">
                <div class="flex justify-between text-gray-500"><span>Subtotal (${formatQty(totals.itemCount)} item)</span><span>${formatRupiah(totals.grossTotal)}</span></div>
                ${totals.discountTotal > 0 ? `<div class="flex justify-between text-amber-600"><span>Diskon</span><span>-${formatRupiah(totals.discountTotal)}</span></div>` : ''}
                <div class="flex justify-between font-semibold text-gray-900 pt-1 border-t border-gray-200"><span>Total Bayar</span><span>${formatRupiah(totals.grandTotal)}</span></div>
            </div>

            <p class="text-xs text-gray-500 mb-1.5">Metode Pembayaran</p>
            <div class="grid grid-cols-4 gap-1.5 mb-4">
                ${methods.map((m) => `
                    <button type="button" data-payment-method="${m.key}"
                            class="py-2 rounded-md border text-xs font-medium ${m.key === selectedMethod ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-300'}">
                        ${m.label}
                    </button>`).join('')}
            </div>

            <div id="pos-cash-fields" class="${isCash ? '' : 'hidden'} mb-4 space-y-2">
                <label class="text-xs text-gray-500">Uang Diterima</label>
                <input type="number" min="0" id="pos-paid-input" value="${paidAmount}"
                       class="w-full rounded-md text-sm border-gray-300">
                <div id="pos-cash-summary-row" class="flex justify-between text-sm font-medium">
                    <span id="pos-cash-summary-label"></span>
                    <span id="pos-cash-summary-amount"></span>
                </div>
            </div>

            <p id="pos-payment-error" class="hidden text-xs text-red-600 bg-red-50 rounded-md px-2.5 py-1.5 mb-3"></p>

            <button type="button" id="pos-payment-submit-btn"
                    class="w-full py-2.5 rounded-md text-sm font-medium">
                Proses Transaksi
            </button>`;

        content.querySelectorAll('[data-payment-method]').forEach((btn) => {
            btn.addEventListener('click', function () {
                const method = btn.getAttribute('data-payment-method');
                renderPaymentContent(totals, method, totals.grandTotal);
            });
        });

        if (isCash) {
            // PENTING: jangan render ulang innerHTML input ini tiap ketukan
            // (itu sebabnya kursor selalu lompat ke kiri sebelumnya — elemen
            // input-nya ikut dibongkar-pasang tiap ketik). Cukup update
            // angka kembalian/kurang & tombol lewat DOM langsung, input-nya
            // sendiri tidak disentuh sama sekali supaya fokus & posisi
            // kursor natural terjaga oleh browser.
            document.getElementById('pos-paid-input').addEventListener('input', function (e) {
                const val = parseInt(e.target.value, 10) || 0;
                updateCashSummary(totals, val, true);
            });
        }

        updateCashSummary(totals, paidAmount, isCash);

        document.getElementById('pos-payment-submit-btn').addEventListener('click', function () {
            const finalPaid = isCash
                ? (parseInt(document.getElementById('pos-paid-input').value, 10) || 0)
                : totals.grandTotal;
            submitCheckout(selectedMethod, finalPaid, totals);
        });
    }

    /** Update tampilan kembalian/kurang + status tombol submit, TANPA menyentuh elemen input uang diterima. */
    function updateCashSummary(totals, paidAmount, isCash) {
        const submitBtn = document.getElementById('pos-payment-submit-btn');
        const insufficient = isCash && paidAmount < totals.grandTotal;

        if (isCash) {
            const input = document.getElementById('pos-paid-input');
            input.classList.toggle('border-red-400', insufficient);
            input.classList.toggle('focus:border-red-500', insufficient);
            input.classList.toggle('focus:ring-red-500', insufficient);
            input.classList.toggle('border-gray-300', !insufficient);

            const change = Math.max(0, paidAmount - totals.grandTotal);
            const label = document.getElementById('pos-cash-summary-label');
            const amount = document.getElementById('pos-cash-summary-amount');
            const row = document.getElementById('pos-cash-summary-row');
            label.textContent = insufficient ? 'Kurang' : 'Kembalian';
            amount.textContent = formatRupiah(insufficient ? totals.grandTotal - paidAmount : change);
            row.classList.toggle('text-red-600', insufficient);
            row.classList.toggle('text-green-600', !insufficient);
        }

        submitBtn.disabled = insufficient;
        submitBtn.classList.toggle('bg-gray-200', insufficient);
        submitBtn.classList.toggle('text-gray-400', insufficient);
        submitBtn.classList.toggle('cursor-not-allowed', insufficient);
        submitBtn.classList.toggle('bg-indigo-600', !insufficient);
        submitBtn.classList.toggle('text-white', !insufficient);
        submitBtn.classList.toggle('hover:bg-indigo-700', !insufficient);
    }

    async function submitCheckout(paymentMethod, paidAmount, totals) {
        const submitBtn = document.getElementById('pos-payment-submit-btn');
        const errorEl = document.getElementById('pos-payment-error');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses...';
        errorEl.classList.add('hidden');

        const payload = {
            payment_method: paymentMethod,
            paid_amount: Math.round(paidAmount),
            items: cart.map((l) => ({
                product_id: l.productId,
                unit_id: l.unitId,
                qty: l.qty,
                discount_type: l.discountType,
                discount_value: l.discountValue,
            })),
        };

        try {
            const res = await fetch(checkoutUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            });
            const body = await res.json().catch(() => null);

            if (res.ok && body?.data) {
                showTransactionSuccess(body.data);
            } else {
                const message = body?.errors
                    ? Object.values(body.errors).flat().join(' ')
                    : (body?.message || 'Transaksi gagal diproses. Coba lagi.');
                errorEl.textContent = message;
                errorEl.classList.remove('hidden');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Proses Transaksi';
                renderCart(); // refresh, kalau-kalau ini gara-gara stok berubah
            }
        } catch (err) {
            errorEl.textContent = 'Tidak bisa terhubung ke server. Cek koneksi lalu coba lagi.';
            errorEl.classList.remove('hidden');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Proses Transaksi';
        }
    }

    function showTransactionSuccess(data) {
        const content = document.getElementById('pos-payment-content');
        content.innerHTML = `
            <div class="text-center py-2">
                <div style="width:3rem;height:3rem;" class="mx-auto w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <p class="font-semibold text-gray-900">Transaksi Berhasil</p>
                <p class="text-xs text-gray-400 mb-4">${escapeHtml(data.invoice_number)}</p>

                <div class="rounded-md bg-gray-50 p-3 text-sm space-y-1 text-left mb-4">
                    <div class="flex justify-between"><span class="text-gray-500">Total Bayar</span><span class="font-medium">${formatRupiah(data.grand_total)}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Dibayar (${data.payment_method})</span><span class="font-medium">${formatRupiah(data.paid_amount)}</span></div>
                    ${data.payment_method === 'cash' ? `<div class="flex justify-between text-green-600 font-semibold"><span>Kembalian</span><span>${formatRupiah(data.change_amount)}</span></div>` : ''}
                </div>

                <a href="${strukUrlBase}/${data.id}/struk" target="_blank" rel="noopener"
                   class="block text-center w-full py-2 rounded-md bg-gray-100 text-gray-700 text-sm mb-2 hover:bg-gray-200">Cetak Struk</a>
                <button type="button" id="pos-transaction-new-btn"
                        class="w-full py-2.5 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">Transaksi Baru</button>
            </div>`;

        document.getElementById('pos-transaction-new-btn').addEventListener('click', function () {
            cart = [];
            renderCart();
            closeModalByName('pos-payment');
            document.getElementById('pos-mobile-cart-overlay')?.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            searchInput.value = '';
            searchInput.focus();
        });
    }

    // ===================== Resolusi produk -> keranjang =====================

    function resolveAndAdd(product, { preferUnitId = null } = {}) {
        const sellableUnits = product.units; // sudah difilter server (selling_price tidak null)

        if (sellableUnits.length === 0) {
            showInlineMessage('Produk ini belum punya satuan yang bisa dijual.', true);
            return;
        }

        if (preferUnitId) {
            const unit = sellableUnits.find((u) => u.id === preferUnitId);
            if (unit) {
                addToCart(product, unit);
                showInlineMessage(`✓ ${product.name} (${unit.unit_name}) ditambahkan.`, false);
                return;
            }
        }

        if (sellableUnits.length === 1) {
            addToCart(product, sellableUnits[0]);
            showInlineMessage(`✓ ${product.name} ditambahkan.`, false);
            return;
        }

        openUnitPicker(product);
    }

    function openUnitPicker(product) {
        const content = document.getElementById('pos-unit-picker-content');
        content.innerHTML = `
            <h3 class="font-medium text-gray-900 mb-1">${escapeHtml(product.name)}</h3>
            <p class="text-xs text-gray-400 mb-3">Pilih satuan jual:</p>
            <div class="space-y-2">
                ${product.units.map((u) => `
                    <button type="button" data-pick-unit="${u.id}"
                            class="w-full flex items-center justify-between px-3 py-2.5 rounded-md border border-gray-200 hover:border-indigo-400 hover:bg-indigo-50 text-left">
                        <span class="text-sm font-medium text-gray-800">${escapeHtml(u.unit_name)}</span>
                        <span class="text-sm text-gray-500">${formatRupiah(u.selling_price)}</span>
                    </button>`).join('')}
            </div>`;

        content.querySelectorAll('[data-pick-unit]').forEach((btn) => {
            btn.addEventListener('click', function () {
                const unitId = parseInt(btn.getAttribute('data-pick-unit'), 10);
                resolveAndAdd(product, { preferUnitId: unitId });
                closeModalByName('pos-unit-picker');
            });
        });

        openModalByName('pos-unit-picker');
    }

    function openDiscountEditor(lineId) {
        const line = cart.find((l) => l.lineId === lineId);
        if (!line) return;

        const content = document.getElementById('pos-discount-content');
        const currentType = line.discountType || 'nominal';
        const currentValue = line.discountValue || 0;

        content.innerHTML = `
            <h3 class="font-medium text-gray-900 mb-1">Diskon — ${escapeHtml(line.productName)}</h3>
            <p class="text-xs text-gray-400 mb-3">${escapeHtml(line.unitName)} &middot; ${formatQty(line.qty)} x ${formatRupiah(line.price)}</p>

            <div class="flex rounded-md border border-gray-300 overflow-hidden mb-3 text-sm">
                <button type="button" data-discount-type="nominal"
                        class="flex-1 py-2 ${currentType === 'nominal' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600'}">Rupiah</button>
                <button type="button" data-discount-type="percent"
                        class="flex-1 py-2 ${currentType === 'percent' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600'}">Persen (%)</button>
            </div>

            <input type="number" min="0" id="pos-discount-value-input" value="${currentValue || ''}"
                   placeholder="0" class="w-full rounded-md border-gray-300 text-sm mb-4">

            <div class="flex gap-2">
                <button type="button" id="pos-discount-remove-btn"
                        class="flex-1 py-2 rounded-md border border-gray-300 text-gray-500 text-sm hover:bg-gray-50">Hapus Diskon</button>
                <button type="button" id="pos-discount-apply-btn"
                        class="flex-1 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">Terapkan</button>
            </div>`;

        let selectedType = currentType;
        content.querySelectorAll('[data-discount-type]').forEach((btn) => {
            btn.addEventListener('click', function () {
                selectedType = btn.getAttribute('data-discount-type');
                content.querySelectorAll('[data-discount-type]').forEach((b) => {
                    const active = b === btn;
                    b.classList.toggle('bg-indigo-600', active);
                    b.classList.toggle('text-white', active);
                    b.classList.toggle('bg-white', !active);
                    b.classList.toggle('text-gray-600', !active);
                });
            });
        });

        document.getElementById('pos-discount-apply-btn').addEventListener('click', function () {
            const val = parseFloat(document.getElementById('pos-discount-value-input').value);
            if (!val || val <= 0) {
                line.discountType = null;
                line.discountValue = 0;
            } else {
                line.discountType = selectedType;
                line.discountValue = selectedType === 'percent' ? Math.min(100, val) : val;
            }
            renderCart();
            closeModalByName('pos-discount');
        });

        document.getElementById('pos-discount-remove-btn').addEventListener('click', function () {
            line.discountType = null;
            line.discountValue = 0;
            renderCart();
            closeModalByName('pos-discount');
        });

        openModalByName('pos-discount');
    }

    // Helper buka/tutup modal manual (kontennya dinamis, jadi tidak bisa
    // sepenuhnya mengandalkan listener statis bawaan components/modal/script.blade.php)
    function openModalByName(name) {
        const modal = document.getElementById('modal-' + name);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function closeModalByName(name) {
        const modal = document.getElementById('modal-' + name);
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    // ===================== Pencarian manual (nama/SKU) =====================

    function productCardHtml(product) {
        const units = product.units;
        const priceLabel = units.length === 1
            ? formatRupiah(units[0].selling_price)
            : 'Mulai ' + formatRupiah(Math.min(...units.map((u) => u.selling_price)));

        const thumb = product.image_url
            ? `<img src="${product.image_url}" style="width:2.5rem;height:2.5rem;object-fit:cover;" class="w-10 h-10 rounded-md object-cover shrink-0" alt="">`
            : `<div style="width:2.5rem;height:2.5rem;" class="w-10 h-10 rounded-md bg-gray-100 flex items-center justify-center shrink-0 text-gray-300">
                   <svg xmlns="http://www.w3.org/2000/svg" style="width:1.25rem;height:1.25rem;" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                       <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                   </svg>
               </div>`;

        return `
            <button type="button" data-product-card="${product.id}"
                    class="w-full flex items-center gap-3 bg-white rounded-lg shadow-sm p-3 text-left hover:ring-2 hover:ring-indigo-200">
                ${thumb}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(product.name)}</p>
                    <p class="text-xs text-gray-400">${escapeHtml(product.sku)} &middot; Stok: ${formatQty(product.stock)} ${escapeHtml(baseUnitLabel(product))}</p>
                </div>
                <div class="text-sm font-semibold text-indigo-600 shrink-0">${priceLabel}</div>
            </button>`;
    }

    function renderSearchResults(products) {
        if (products.length === 0) {
            resultsContainer.innerHTML = `
                <div class="bg-white rounded-lg shadow-sm p-10 text-center text-sm text-gray-400">
                    Produk tidak ditemukan.
                </div>`;
            return;
        }

        resultsContainer.innerHTML = products.map(productCardHtml).join('');

        resultsContainer.querySelectorAll('[data-product-card]').forEach((card) => {
            card.addEventListener('click', function () {
                const product = products.find((p) => p.id === parseInt(card.getAttribute('data-product-card'), 10));
                if (product) resolveAndAdd(product);
            });
        });
    }

    let searchToken = 0;

    function setSearchLoading(isLoading) {
        document.getElementById('pos-search-icon-wrap')?.classList.toggle('hidden', isLoading);
        document.getElementById('pos-search-spinner')?.classList.toggle('hidden', !isLoading);
    }

    const doSearch = debounce(async function (q) {
        if (q.trim().length < 2) {
            searchToken++; // batalkan/abaikan request lama yang mungkin masih berjalan
            setSearchLoading(false);
            resultsContainer.innerHTML = '';
            resultsContainer.appendChild(emptyState);
            return;
        }

        const myToken = ++searchToken;
        setSearchLoading(true);
        resultsContainer.innerHTML = `
            <div class="bg-white rounded-lg shadow-sm p-10 text-center text-sm text-gray-400">
                Mencari...
            </div>`;

        const { ok, body } = await fetchJson(`${searchUrl}?q=${encodeURIComponent(q)}`);

        // Kalau sudah ada pencarian yang lebih baru menyusul selagi request
        // ini masih di jalan, abaikan hasilnya — jangan sampai jawaban lama
        // yang telat datang menimpa hasil pencarian terbaru di layar.
        if (myToken !== searchToken) return;

        setSearchLoading(false);

        if (!ok || !body) {
            showInlineMessage('Gagal memuat hasil pencarian. Coba lagi.', true);
            resultsContainer.innerHTML = '';
            resultsContainer.appendChild(emptyState);
            return;
        }
        renderSearchResults(body.data);
    }, 300);

    searchInput.addEventListener('input', function () {
        doSearch(searchInput.value);
    });

    // ===================== Scan barcode: submit lewat Enter (USB/manual) =====================

    async function handleScannedCode(code) {
        code = (code || '').trim();
        if (!code) return;

        const { ok, status, body } = await fetchJson(`${barcodeUrlBase}/${encodeURIComponent(code)}`);

        if (ok && body?.data) {
            resolveAndAdd(body.data, { preferUnitId: body.matched_unit_id });
        } else if (status === 401 || status === 419) {
            showInlineMessage('Sesi login habis, silakan muat ulang halaman.', true);
        } else {
            showInlineMessage(body?.message || 'Kode tidak dikenali.', true);
        }
    }

    searchInput.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();

        const value = searchInput.value.trim();
        if (!value) return;

        searchToken++; // batalkan pencarian nama yang mungkin masih tertunda dari ketikan sebelumnya
        setSearchLoading(false);
        handleScannedCode(value);
        searchInput.value = '';
        resultsContainer.innerHTML = '';
        resultsContainer.appendChild(emptyState);
    });

    // ===================== Scanner USB tanpa fokus ke kolom (fallback) =====================
    // Scanner HID mengetik sangat cepat lalu kirim Enter. Kalau fokus sedang
    // TIDAK berada di elemen input/textarea lain (misal fokus di body/tombol
    // setelah klik kartu produk), tangkap ketikan cepat ini secara global
    // supaya kasir benar-benar tidak perlu klik kolom pencarian dulu.
    let scanBuffer = '';
    let lastKeyTime = 0;

    document.addEventListener('keydown', function (e) {
        const active = document.activeElement;
        const isTypingElsewhere = active && active !== searchInput
            && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA');
        if (isTypingElsewhere) return; // jangan ganggu input lain (mis. qty di keranjang, modal satuan)
        if (active === searchInput) return; // sudah ditangani listener Enter di atas

        const now = Date.now();
        if (now - lastKeyTime > 80) scanBuffer = '';
        lastKeyTime = now;

        if (e.key === 'Enter') {
            if (scanBuffer.length >= 3) {
                handleScannedCode(scanBuffer);
            }
            scanBuffer = '';
        } else if (e.key.length === 1) {
            scanBuffer += e.key;
        }
    });

    // ===================== Scan kamera & upload foto =====================

    let activeCameraScanner = null;

    function stopCamera() {
        if (activeCameraScanner) {
            const s = activeCameraScanner;
            activeCameraScanner = null;
            s.stop().then(() => s.clear()).catch(() => {});
        }
    }

    document.getElementById('pos-scan-camera-btn')?.addEventListener('click', function () {
        openModalByName('pos-camera');
        setTimeout(function () {
            const scanner = new Html5Qrcode('pos-camera-region');
            activeCameraScanner = scanner;
            scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 150 } },
                function onSuccess(decodedText) {
                    stopCamera();
                    closeModalByName('pos-camera');
                    handleScannedCode(decodedText);
                },
                function onScanFailure() {},
            ).catch(function (err) {
                console.error('Gagal mengakses kamera:', err);
                showInlineMessage('Tidak bisa mengakses kamera. Coba upload foto sebagai alternatif.', true);
                closeModalByName('pos-camera');
            });
        }, 150);
    });

    document.getElementById('pos-camera-stop-btn')?.addEventListener('click', stopCamera);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') stopCamera();
    });

    document.getElementById('pos-scan-upload-input')?.addEventListener('change', function (e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;

        const scanner = new Html5Qrcode('pos-camera-region');
        scanner.scanFile(file, false)
            .then(function (decodedText) { handleScannedCode(decodedText); })
            .catch(function () {
                showInlineMessage('Barcode tidak terdeteksi dari foto itu. Coba foto lain atau ketik manual.', true);
            });
    });

    // Render awal (keranjang kosong)
    renderCart();
});
