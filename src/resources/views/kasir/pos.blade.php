<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800">Kasir — Point of Sale</h2>
            <a href="{{ route('kasir.riwayat') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-indigo-600">
                <x-icon name="archive" class="w-4 h-4" />
                Riwayat Transaksi
            </a>
        </div>
    </x-slot>

    <div id="kasir-pos-root"
         class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
         data-search-url="{{ route('kasir.pos.cari') }}"
         data-barcode-url="{{ url('/kasir/pos/barcode') }}"
         data-checkout-url="{{ route('kasir.pos.checkout') }}"
         data-struk-url-base="{{ url('/kasir/riwayat') }}"
         data-struk-url-base="{{ url('/kasir/riwayat') }}">

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            {{-- ================= KIRI: Pencarian & Hasil ================= --}}
            <div class="lg:col-span-3 space-y-4">

                <div class="bg-white rounded-lg shadow-sm p-4">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <span id="pos-search-icon-wrap" class="absolute left-3 top-1/2 -translate-y-1/2">
                                <x-icon name="search" class="w-4 h-4 text-gray-400" />
                            </span>
                            <svg id="pos-search-spinner" class="hidden animate-spin w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-indigo-500"
                                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <input type="text" id="pos-search-input" autocomplete="off" autofocus
                                   placeholder="Ketik nama/SKU produk, atau tembak barcode..."
                                   class="w-full pl-9 pr-3 py-2.5 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <button type="button" id="pos-scan-camera-btn"
                                class="shrink-0 px-3 py-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-700"
                                title="Scan pakai kamera">
                            <x-icon name="camera" class="w-5 h-5" />
                        </button>
                        <label class="shrink-0 px-3 py-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-700 cursor-pointer"
                               title="Upload foto barcode">
                            <x-icon name="upload" class="w-5 h-5" />
                            <input type="file" accept="image/*" id="pos-scan-upload-input" class="hidden">
                        </label>
                    </div>

                    <p class="mt-2 text-xs text-gray-400 flex items-center gap-1.5">
                        <x-icon name="barcode" class="w-3.5 h-3.5 shrink-0" />
                        Scanner USB otomatis aktif kapan saja — tidak perlu klik kolom dulu, langsung tembak.
                    </p>

                    <p id="pos-inline-message" class="mt-2 text-xs font-medium hidden"></p>
                </div>

                <div id="pos-search-results" class="space-y-2">
                    <div id="pos-empty-state" class="bg-white rounded-lg shadow-sm p-10 text-center text-sm text-gray-400">
                        Ketik nama produk atau scan barcode untuk mulai.
                    </div>
                </div>
            </div>

            {{-- ================= KANAN: Keranjang (desktop) ================= --}}
            <div class="hidden lg:block lg:col-span-2">
                <div class="bg-white rounded-lg shadow-sm sticky top-4 flex flex-col overflow-hidden" style="max-height: calc(100vh - 2rem);">
                    @include('kasir.partials.cart-panel')
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Keranjang mobile: tombol mengambang + overlay ================= --}}
    <button type="button" id="pos-mobile-cart-btn"
            class="lg:hidden hidden fixed bottom-24 inset-x-4 z-30 items-center justify-between gap-3 rounded-full bg-indigo-600 text-white px-5 py-3 shadow-lg">
        <span class="flex items-center gap-2 text-sm font-medium">
            <x-icon name="cart" class="w-5 h-5" />
            <span class="cart-item-count">0</span> item
        </span>
        <span class="cart-total-display text-sm font-semibold">Rp 0</span>
    </button>

    <div id="pos-mobile-cart-overlay" class="lg:hidden fixed inset-0 z-40 hidden">
        <div class="absolute inset-0 bg-gray-900/50" data-pos-cart-close></div>
        <div class="absolute bottom-0 inset-x-0 bg-white rounded-t-2xl shadow-xl flex flex-col" style="max-height: 85vh;">
            @include('kasir.partials.cart-panel')
        </div>
    </div>

    {{-- ================= Modal: kamera scan ================= --}}
    <x-modal.modal name="pos-camera" maxWidth="md">
        <div class="p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-medium text-gray-900">Scan Barcode</h3>
                <button type="button" data-modal-close id="pos-camera-stop-btn" class="text-gray-400 hover:text-gray-600">
                    <x-icon name="x" class="w-5 h-5" />
                </button>
            </div>
            <div id="pos-camera-region" class="rounded-lg overflow-hidden bg-gray-900 min-h-[250px]"></div>
            <p class="text-xs text-gray-400 mt-2">Arahkan kamera ke barcode produk. Otomatis terdeteksi &amp; ditambahkan ke keranjang.</p>
        </div>
    </x-modal.modal>

    {{-- ================= Modal: pilih satuan jual ================= --}}
    <x-modal.modal name="pos-unit-picker" maxWidth="sm">
        <div class="p-4" id="pos-unit-picker-content">
            {{-- diisi dinamis oleh kasir-pos.js --}}
        </div>
    </x-modal.modal>

    {{-- ================= Modal: diskon per item ================= --}}
    <x-modal.modal name="pos-discount" maxWidth="sm">
        <div class="p-4" id="pos-discount-content">
            {{-- diisi dinamis oleh kasir-pos.js --}}
        </div>
    </x-modal.modal>

    {{-- ================= Modal: pembayaran/checkout ================= --}}
    <x-modal.modal name="pos-payment" maxWidth="sm">
        <div class="p-4" id="pos-payment-content">
            {{-- diisi dinamis oleh kasir-pos.js --}}
        </div>
    </x-modal.modal>
</x-app-layout>
