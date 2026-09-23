<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Produk') }}
            </h2>
            <button type="button" data-modal-open="create-product"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                + Tambah Produk
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Filter — desktop/tablet (≥768px), tidak berubah -->
            <form method="GET" class="hidden md:flex mb-4 bg-white p-4 rounded-lg shadow-sm flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Cari</label>
                    <input type="text" name="search" value="{{ request('search') }}"
                           placeholder="Nama, SKU, atau barcode satuan..."
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Kategori</label>
                    <select name="category_id" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua Kategori</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected(request('category_id') == $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        <option value="active" @selected(request('status') === 'active')>Aktif</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-600 pb-2">
                    <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Stok menipis
                </label>

                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">
                    Filter
                </button>
                @if (request()->anyFilled(['search', 'category_id', 'status', 'low_stock']))
                    <a href="{{ route('gudang.produk.index') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                        Reset
                    </a>
                @endif
            </form>

            <!-- Filter — mobile (<768px): dilipat (accordion), field & nama input SAMA
                 persis dengan versi desktop di atas supaya query string yang dihasilkan
                 identik, cuma tampilannya yang beda. -->
            <div class="md:hidden mb-4">
                <button type="button" data-collapse-toggle="filter-produk-mobile"
                        class="w-full flex items-center justify-between bg-white border border-gray-200 rounded-lg px-4 py-3 text-sm font-semibold text-gray-700">
                    <span>Cari &amp; Filter Produk</span>
                    <x-icon name="chevron-down" class="w-4 h-4 text-gray-400 transition-transform" data-collapse-chevron="filter-produk-mobile" />
                </button>
                <form method="GET" id="filter-produk-mobile" class="hidden mt-2 bg-white border border-gray-200 rounded-lg p-4 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Cari</label>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Nama, SKU, atau barcode satuan..."
                               class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Kategori</label>
                        <select name="category_id" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua Kategori</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected(request('category_id') == $cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                        <select name="status" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            <option value="active" @selected(request('status') === 'active')>Aktif</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        Stok menipis
                    </label>
                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">
                            Terapkan Filter
                        </button>
                        @if (request()->anyFilled(['search', 'category_id', 'status', 'low_stock']))
                            <a href="{{ route('gudang.produk.index') }}"
                               class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md text-sm font-medium">
                                Reset
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Tabel — desktop/tablet (≥768px), tidak berubah -->
            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kategori</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Satuan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Harga Jual</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Stok</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            @php $baseUnit = $product->units->firstWhere('is_base_unit', true); @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($product->image)
                                            <img src="{{ Storage::url($product->image) }}" class="w-10 h-10 rounded-md object-cover border border-gray-200">
                                        @else
                                            <div class="w-10 h-10 rounded-md bg-gray-100 flex items-center justify-center text-gray-300">
                                                <x-icon name="cube" class="w-5 h-5" />
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-medium text-gray-900">{{ $product->name }}</div>
                                            <div class="text-xs text-gray-400">
                                                {{ $product->sku }} ·
                                                <span @class([
                                                    'text-indigo-500' => $product->tracking_mode === 'unit',
                                                    'text-amber-500' => $product->tracking_mode === 'weight',
                                                ])>{{ $product->trackingModeLabel() }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $product->category->name }}</td>
                                <td class="px-4 py-3 text-gray-500">
                                    @forelse ($product->units as $unit)
                                        <span @class([
                                            'inline-block px-1.5 py-0.5 rounded text-xs mr-1 mb-1',
                                            'bg-indigo-50 text-indigo-700 font-medium' => $unit->is_base_unit,
                                            'bg-gray-50 text-gray-600' => ! $unit->is_base_unit,
                                        ])>{{ $unit->unit_name }}</span>
                                    @empty
                                        <span class="text-xs text-red-500">Belum ada satuan</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3 text-right text-gray-900">
                                    @if ($baseUnit)
                                        Rp {{ number_format($baseUnit->selling_price ?? 0, 0, ',', '.') }}
                                        <span class="text-xs text-gray-400">/ {{ $baseUnit->unit_name }}</span>
                                    @else
                                        <span class="text-xs text-red-500">Belum ada satuan dasar</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap',
                                        'bg-red-100 text-red-700' => $product->isLowStock(),
                                        'bg-gray-100 text-gray-700' => ! $product->isLowStock(),
                                    ])>
                                        {{ $product->formatStock((float) $product->stock) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700' => $product->is_active,
                                        'bg-gray-100 text-gray-500' => ! $product->is_active,
                                    ])>
                                        {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('gudang.produk.show', $product) }}"
                                       class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-gray-100 text-gray-700 hover:bg-gray-200">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada produk yang cocok dengan filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Kartu — mobile (<768px): data & aksi SAMA persis dengan tabel di atas,
                 cuma layoutnya ditumpuk supaya tidak perlu digeser horizontal. -->
            <div class="md:hidden space-y-2.5">
                @forelse ($products as $product)
                    @php $baseUnit = $product->units->firstWhere('is_base_unit', true); @endphp
                    <div class="bg-white border border-gray-200 rounded-lg p-3">
                        <div class="flex items-start gap-3">
                            @if ($product->image)
                                <img src="{{ Storage::url($product->image) }}" class="w-11 h-11 rounded-md object-cover border border-gray-200 flex-none">
                            @else
                                <div class="w-11 h-11 rounded-md bg-gray-100 flex items-center justify-center text-gray-300 flex-none">
                                    <x-icon name="cube" class="w-5 h-5" />
                                </div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="font-medium text-gray-900 truncate">{{ $product->name }}</div>
                                        <div class="text-xs text-gray-400">
                                            {{ $product->sku }} · {{ $product->category->name }}
                                        </div>
                                    </div>
                                    <span @class([
                                        'flex-none inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-semibold whitespace-nowrap',
                                        'bg-emerald-100 text-emerald-700' => $product->is_active,
                                        'bg-gray-100 text-gray-500' => ! $product->is_active,
                                    ])>
                                        {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </div>

                                <div class="mt-1.5 flex flex-wrap gap-1">
                                    @forelse ($product->units as $unit)
                                        <span @class([
                                            'inline-block px-1.5 py-0.5 rounded text-[10.5px]',
                                            'bg-indigo-50 text-indigo-700 font-medium' => $unit->is_base_unit,
                                            'bg-gray-50 text-gray-600' => ! $unit->is_base_unit,
                                        ])>{{ $unit->unit_name }}</span>
                                    @empty
                                        <span class="text-[10.5px] text-red-500">Belum ada satuan</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="mt-2.5 pt-2.5 border-t border-dashed border-gray-200 flex items-end justify-between">
                            <div>
                                @if ($baseUnit)
                                    <div class="font-semibold text-gray-900 text-sm">
                                        Rp {{ number_format($baseUnit->selling_price ?? 0, 0, ',', '.') }}
                                        <span class="text-xs font-normal text-gray-400">/ {{ $baseUnit->unit_name }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-red-500">Belum ada satuan dasar</span>
                                @endif
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-medium mt-1',
                                    'bg-red-100 text-red-700' => $product->isLowStock(),
                                    'bg-gray-100 text-gray-700' => ! $product->isLowStock(),
                                ])>
                                    {{ $product->formatStock((float) $product->stock) }}
                                </span>
                            </div>
                            <a href="{{ route('gudang.produk.show', $product) }}"
                               class="flex-none px-3 py-1.5 rounded-md text-xs font-semibold bg-gray-100 text-gray-700">
                                Detail
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-gray-200 rounded-lg p-6 text-center text-gray-400 text-sm">
                        Belum ada produk yang cocok dengan filter ini.
                    </div>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $products->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Create -->
    <x-modal.modal name="create-product" maxWidth="2xl">
        <form method="POST" action="{{ route('gudang.produk.store') }}" enctype="multipart/form-data" class="p-6">
            @csrf
            <h3 class="text-lg font-medium text-gray-900 mb-4">Tambah Produk</h3>

            @include('gudang.produk._form', ['product' => null, 'formId' => 'create-product'])

            <div class="flex justify-end gap-2 mt-6">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan Produk</x-primary-button>
            </div>
        </form>
    </x-modal.modal>

    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>