@php
    $navItem = function (string $route, string $label, string $icon, bool $exact = false) {
        $active = $exact ? request()->routeIs($route) : request()->routeIs($route.'*');
        return compact('route', 'label', 'icon', 'active');
    };
@endphp

<!-- Overlay (mobile only, klik untuk tutup sidebar) -->
<div id="sidebar-overlay" data-sidebar-close
     class="hidden fixed inset-0 bg-gray-900/50 z-30 lg:hidden"></div>

<aside id="sidebar"
       class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 flex flex-col
              -translate-x-full transition-transform duration-200 ease-in-out
              lg:translate-x-0 lg:static lg:inset-auto">

    <!-- Brand -->
    <div class="h-16 flex items-center px-6 border-b border-gray-100 shrink-0">
        <a href="{{ route('dashboard') }}" class="font-bold text-xl text-indigo-600">
            Toko<span class="text-gray-800">App</span>
        </a>
    </div>

    <!-- Nav Links -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        @foreach ([
            $navItem('dashboard', 'Dashboard', 'home', true),
        ] as $item)
            <x-sidebar-link :item="$item" />
        @endforeach

        @if (auth()->user()->isAdmin() || auth()->user()->isGudang())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Gudang</p>
            <x-sidebar-link :item="$navItem('gudang.kategori.index', 'Kategori', 'tag')" />
            <x-sidebar-link :item="$navItem('gudang.produk.index', 'Produk', 'cube')" />
            <x-sidebar-link :item="$navItem('gudang.stok.index', 'Stok', 'archive')" />
        @endif

        @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Kasir</p>
            <x-sidebar-link :item="$navItem('kasir.pos', 'POS / Transaksi', 'cart', true)" />
            <x-sidebar-link :item="$navItem('kasir.produk', 'Lihat Produk', 'eye', true)" />
        @endif

        @if (auth()->user()->isAdmin())
            <p class="px-3 pt-4 pb-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Admin</p>
            <x-sidebar-link :item="$navItem('admin.users', 'Manajemen User', 'users', true)" />
            <x-sidebar-link :item="$navItem('admin.laporan', 'Laporan', 'chart', true)" />
        @endif
    </nav>

    <!-- User info + role badge -->
    <div class="border-t border-gray-100 p-4 shrink-0">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-9 h-9 rounded-full bg-indigo-600 text-white flex items-center justify-center font-semibold text-sm shrink-0">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ auth()->user()->name }}</p>
                <span @class([
                    'inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full mt-0.5',
                    'bg-indigo-100 text-indigo-700' => auth()->user()->isAdmin(),
                    'bg-emerald-100 text-emerald-700' => auth()->user()->isKasir(),
                    'bg-amber-100 text-amber-700' => auth()->user()->isGudang(),
                ])>
                    {{ ucfirst(auth()->user()->role) }}
                </span>
            </div>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <a href="{{ route('profile.edit') }}" class="text-gray-500 hover:text-indigo-600">Profil</a>
            <span class="text-gray-300">&middot;</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-gray-500 hover:text-red-600">Keluar</button>
            </form>
        </div>
    </div>
</aside>