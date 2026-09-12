{{--
    PENTING: partial ini di-@include DUA KALI di kasir/pos.blade.php (versi
    desktop yang selalu tampil di sidebar, dan versi mobile di dalam overlay).
    Karena itu elemen di dalam sini WAJIB pakai class (bukan id) untuk apa pun
    yang perlu diakses/diupdate oleh JS — kalau pakai id, browser cuma akan
    mengenali salah satu (yang pertama ketemu di DOM), dan panel yang satunya
    tidak akan pernah ke-update. Lihat kasir-pos.js: semua query pakai
    `document.querySelectorAll('.cart-...')` lalu di-loop ke SEMUA panel.
--}}
<div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 shrink-0">
    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
        <x-icon name="cart" class="w-5 h-5 text-indigo-600" />
        Keranjang
    </h3>
    <button type="button" data-pos-cart-close class="lg:hidden text-gray-400 hover:text-gray-600 p-1">
        <x-icon name="x" class="w-5 h-5" />
    </button>
</div>

<div class="cart-items-container flex-1 overflow-y-auto divide-y divide-gray-100">
    <p class="cart-empty-state px-4 py-10 text-center text-sm text-gray-400">
        Keranjang masih kosong.<br>Cari atau scan produk untuk mulai.
    </p>
</div>

<div class="border-t border-gray-100 px-4 py-3 space-y-1.5 shrink-0">
    <p class="cart-stock-warning hidden text-xs font-medium text-red-600 bg-red-50 rounded-md px-2.5 py-1.5 mb-1">
        ⚠ Ada item yang melebihi stok tersedia — perbaiki dulu sebelum lanjut.
    </p>
    <div class="flex items-center justify-between text-xs text-gray-500">
        <span>Subtotal (<span class="cart-item-count">0</span> item)</span>
        <span class="cart-subtotal-display">Rp 0</span>
    </div>
    <div class="cart-discount-row hidden items-center justify-between text-xs text-green-600">
        <span>Diskon</span>
        <span class="cart-discount-display">-Rp 0</span>
    </div>
    <div class="flex items-center justify-between text-sm pt-1 border-t border-gray-50">
        <span class="font-medium text-gray-700">Total</span>
        <span class="cart-total-display font-semibold text-gray-900 text-base">Rp 0</span>
    </div>
    <button type="button" disabled data-cart-checkout
            class="cart-checkout-btn w-full py-2.5 rounded-md bg-gray-200 text-gray-400 text-sm font-medium cursor-not-allowed transition-colors">
        Lanjut ke Pembayaran
    </button>
</div>
