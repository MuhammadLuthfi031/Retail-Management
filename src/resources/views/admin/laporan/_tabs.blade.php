@php
    $tabs = [
        ['route' => 'admin.laporan.penjualan', 'label' => 'Penjualan'],
        ['route' => 'admin.laporan.laba-rugi', 'label' => 'Laba/Rugi'],
        ['route' => 'admin.laporan.stok', 'label' => 'Stok'],
    ];
@endphp

<div class="mb-4 border-b border-gray-200">
    <nav class="-mb-px flex gap-6">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               @class([
                   'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium',
                   'border-indigo-600 text-indigo-600' => request()->routeIs($tab['route']),
                   'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' => ! request()->routeIs($tab['route']),
               ])>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
