<aside class="hidden md:flex md:flex-col md:fixed md:inset-y-0 md:w-64 bg-white border-r border-gray-200 z-30">
    <!-- Logo -->
    <div class="h-16 flex items-center px-6 border-b border-gray-100">
        <a href="{{ route('dashboard') }}" class="font-bold text-xl text-indigo-600">
            Toko<span class="text-gray-800">App</span>
        </a>
    </div>

    <!-- Role badge -->
    <div class="px-6 py-4">
        <span @class([
            'text-xs font-semibold px-2.5 py-1 rounded-full',
            'bg-indigo-100 text-indigo-700' => auth()->user()->isAdmin(),
            'bg-emerald-100 text-emerald-700' => auth()->user()->isKasir(),
            'bg-amber-100 text-amber-700' => auth()->user()->isGudang(),
        ])>
            {{ ucfirst(auth()->user()->role) }}
        </span>
    </div>

    <!-- Nav items -->
    <nav class="flex-1 px-3 space-y-1 overflow-y-auto">
        @php
            $navLink = function ($route, $label, $icon) {
                $active = request()->routeIs($route . '*');
                $classes = $active
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900';
                return compact('active', 'classes');
            };
        @endphp

        <a href="{{ route('dashboard') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('dashboard', '', '')['classes'] }}">
            <x-icon name="home" />
            Dashboard
        </a>

        @if (auth()->user()->isAdmin() || auth()->user()->isGudang())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Gudang</p>

            <a href="{{ route('gudang.kategori.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('gudang.kategori', '', '')['classes'] }}">
                <x-icon name="tag" />
                Kategori
            </a>
            <a href="{{ route('gudang.produk.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('gudang.produk', '', '')['classes'] }}">
                <x-icon name="cube" />
                Produk
            </a>
            <a href="{{ route('gudang.stok.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('gudang.stok', '', '')['classes'] }}">
                <x-icon name="archive" />
                Stok
            </a>
            <a href="{{ route('gudang.pembelian.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('gudang.pembelian', '', '')['classes'] }}">
                <x-icon name="truck" />
                Terima Barang
            </a>
        @endif

        @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Kasir</p>

            <a href="{{ route('kasir.pos') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('kasir.pos', '', '')['classes'] }}">
                <x-icon name="cart" />
                POS
            </a>
        @endif

        @if (auth()->user()->isAdmin())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Admin</p>

            <a href="{{ route('admin.pembelian.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('admin.pembelian', '', '')['classes'] }}">
                <x-icon name="cart" />
                Purchase Order
            </a>
            <a href="{{ route('admin.supplier.index') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('admin.supplier', '', '')['classes'] }}">
                <x-icon name="truck" />
                Supplier
            </a>
            <a href="{{ route('admin.users') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('admin.users', '', '')['classes'] }}">
                <x-icon name="users" />
                Users
            </a>
            <a href="{{ route('admin.laporan') }}"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ $navLink('admin.laporan', '', '')['classes'] }}">
                <x-icon name="chart" />
                Laporan
            </a>
        @endif
    </nav>

    <!-- User section -->
    <div class="border-t border-gray-100 p-3 space-y-1">
        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition">
            <x-icon name="user-circle" />
            <span class="truncate">{{ auth()->user()->name }}</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-red-50 hover:text-red-700 transition">
                <x-icon name="logout" />
                Keluar
            </button>
        </form>
    </div>
</aside>