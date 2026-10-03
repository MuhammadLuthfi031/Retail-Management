{{--
    Cangkang modal retur penjualan — dipakai bersama oleh Riwayat Transaksi
    (kasir & admin) dan halaman Retur Penjualan (admin). Isinya diambil saat
    tombol [data-retur-open] diklik; logikanya di resources/js/retur-modal.js.
    Buka/tutup mengikuti sistem modal bawaan (data-modal / data-modal-close / Esc).
--}}
<x-modal.modal name="retur" maxWidth="2xl">
    <div class="sticky top-0 z-10 bg-white flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
        <div class="min-w-0">
            <h3 class="font-semibold text-gray-900">Retur Penjualan</h3>
            <p class="text-xs text-gray-500 truncate" data-retur-subtitle></p>
        </div>
        <button type="button" data-modal-close aria-label="Tutup"
                class="flex-none inline-flex items-center justify-center w-10 h-10 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-50">
            <x-icon name="x" class="w-5 h-5" />
        </button>
    </div>

    <div class="hidden mx-5 mt-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800" role="alert" data-retur-errors></div>

    <div class="p-5" data-retur-body></div>
</x-modal.modal>