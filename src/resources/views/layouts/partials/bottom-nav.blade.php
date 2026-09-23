@php
    $isActive = fn ($pattern) => request()->routeIs($pattern) ? 'text-indigo-600' : 'text-gray-500';

    // Jumlah kolom dihitung dari item yang BENERAN dirender di bawah (bukan
    // ditebak dari role), supaya tidak pernah ada slot kosong ganjil. Kasir:
    // Beranda+POS+Lainnya=3. Gudang: Beranda+Produk+Stok+Lainnya=4. Admin:
    // Beranda+POS+Produk+Stok+Lainnya=5. grid-cols-3/4/5 sengaja ditulis
    // sebagai string literal di sini (bukan digabung dinamis) supaya
    // ke-scan Tailwind dan classnya benar-benar ikut ter-compile.
    $navCount = 2; // Beranda + Lainnya selalu ada
    if (auth()->user()->isAdmin() || auth()->user()->isKasir()) $navCount++;
    if (auth()->user()->isAdmin() || auth()->user()->isGudang()) $navCount += 2; // Produk & Stok
    $navGridClass = match ($navCount) {
        3 => 'grid-cols-3',
        4 => 'grid-cols-4',
        default => 'grid-cols-5',
    };
@endphp

<nav class="md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]">
    <div class="grid h-16 {{ $navGridClass }}">
        <a href="{{ route(auth()->user()->homeRouteName()) }}" class="flex flex-col items-center justify-center gap-0.5 {{ $isActive(auth()->user()->homeRouteName()) }}">
            <x-icon name="home" class="w-6 h-6" />
            <span class="text-[11px] font-medium">Beranda</span>
        </a>

        @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
            <a href="{{ route('kasir.pos') }}" class="flex flex-col items-center justify-center gap-0.5 {{ $isActive('kasir.pos') }}">
                <x-icon name="cart" class="w-6 h-6" />
                <span class="text-[11px] font-medium">POS</span>
            </a>
        @endif

        @if (auth()->user()->isAdmin() || auth()->user()->isGudang())
            <a href="{{ route('gudang.produk.index') }}" class="flex flex-col items-center justify-center gap-0.5 {{ $isActive('gudang.produk*') }}">
                <x-icon name="cube" class="w-6 h-6" />
                <span class="text-[11px] font-medium">Produk</span>
            </a>
        @endif

        @if (auth()->user()->isAdmin() || auth()->user()->isGudang())
            <a href="{{ route('gudang.stok.index') }}" class="flex flex-col items-center justify-center gap-0.5 {{ $isActive('gudang.stok*') }}">
                <x-icon name="archive" class="w-6 h-6" />
                <span class="text-[11px] font-medium">Stok</span>
            </a>
        @endif

        <button type="button" data-sheet-open="mobile-more"
                class="flex flex-col items-center justify-center gap-0.5 text-gray-500">
            <x-icon name="dots" class="w-6 h-6" />
            <span class="text-[11px] font-medium">Lainnya</span>
        </button>
    </div>
</nav>

<!-- Sheet: menu tambahan, dikelompokkan per bagian (sama seperti pengelompokan
     di sidebar.blade.php) supaya tidak jadi daftar rata yang panjang -->
<x-modal.sheet name="mobile-more">
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <h3 class="font-semibold text-gray-900">Menu Lainnya</h3>
        <button type="button" data-sheet-close class="text-gray-400 hover:text-gray-600">
            <x-icon name="x" class="w-5 h-5" />
        </button>
    </div>

    <div class="p-2">
        @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
            <p class="px-3 pt-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Kasir</p>
            <a href="{{ route('kasir.riwayat') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="archive" />
                Riwayat Transaksi
            </a>
            <a href="{{ route('kasir.produk') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="cube" />
                Produk & Stok
            </a>
        @endif

        @if (auth()->user()->isAdmin() || auth()->user()->isGudang())
            <p class="px-3 pt-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Gudang</p>
            <a href="{{ route('gudang.kategori.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="tag" />
                Kategori
            </a>
            {{-- Link "Stok" sengaja dihapus dari sini -- kondisinya sama persis
                 dengan tab "Stok" di bottom nav di atas, jadi selalu dobel
                 untuk role manapun yang bisa melihat menu ini. --}}
            <a href="{{ route('gudang.pembelian.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="truck" />
                Terima Barang
            </a>
        @endif

        @if (auth()->user()->isAdmin())
            <p class="px-3 pt-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Admin</p>
            <a href="{{ route('admin.pembelian.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="cart" />
                Purchase Order
            </a>
            <a href="{{ route('admin.supplier.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="truck" />
                Supplier
            </a>
            <a href="{{ route('admin.users.index') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="users" />
                Manajemen User
            </a>
            <a href="{{ route('admin.laporan.penjualan') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                <x-icon name="chart" />
                Laporan
            </a>
        @endif

        <p class="px-3 pt-3 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Akun</p>

        <button type="button" data-fullscreen-toggle
                class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
            <span data-fullscreen-icon-expand><x-icon name="expand" /></span>
            <span data-fullscreen-icon-compress class="hidden"><x-icon name="compress" /></span>
            <span data-fullscreen-label>Fullscreen</span>
        </button>

        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
            <x-icon name="user-circle" />
            Profil Saya
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50">
                <x-icon name="logout" />
                Keluar
            </button>
        </form>
    </div>
</x-modal.sheet>