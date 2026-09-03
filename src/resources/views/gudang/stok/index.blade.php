<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Stok') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Filter -->
            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Cari</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama atau SKU..."
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
                <label class="flex items-center gap-2 text-sm text-gray-600 pb-2">
                    <input type="checkbox" name="low_stock" value="1" @checked(request('low_stock'))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Stok menipis
                </label>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">Filter</button>
                @if (request()->anyFilled(['search', 'category_id', 'low_stock']))
                    <a href="{{ route('gudang.stok.index') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Reset</a>
                @endif
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kategori</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Stok Saat Ini</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Ambang Menipis</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ $product->name }}
                                    <div class="text-xs text-gray-400 font-normal">{{ $product->sku }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $product->category->name }}</td>
                                <td class="px-4 py-3 text-right">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap',
                                        'bg-red-100 text-red-700' => $product->isLowStock(),
                                        'bg-gray-100 text-gray-700' => ! $product->isLowStock(),
                                    ])>
                                        {{ rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}
                                        {{ $product->baseUnit->unit_name ?? '' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">
                                    {{ rtrim(rtrim(number_format((float) $product->min_stock, 3, '.', ''), '0'), '.') }}
                                    {{ $product->baseUnit->unit_name ?? '' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap space-x-1">
                                    <a href="{{ route('gudang.stok.show', $product) }}" class="text-xs font-medium text-gray-500 hover:text-gray-700 px-2 py-1">
                                        Riwayat
                                    </a>
                                    <button type="button" data-modal-open="stok-keluar-{{ $product->id }}"
                                            class="text-xs font-semibold uppercase tracking-wide text-red-600 hover:bg-red-50 px-2 py-1 rounded-md">
                                        Keluar
                                    </button>
                                    <button type="button" data-modal-open="stok-mutasi-{{ $product->id }}"
                                            class="text-xs font-semibold uppercase tracking-wide text-blue-600 hover:bg-blue-50 px-2 py-1 rounded-md">
                                        Mutasi
                                    </button>
                                    <button type="button" data-modal-open="stok-opname-{{ $product->id }}"
                                            class="text-xs font-semibold uppercase tracking-wide text-amber-600 hover:bg-amber-50 px-2 py-1 rounded-md">
                                        Opname
                                    </button>
                                </td>
                            </tr>

                            <!-- Modal Stok Keluar -->
                            <x-modal.modal name="stok-keluar-{{ $product->id }}">
                                <form method="POST" action="{{ route('gudang.stok.keluar', $product) }}" class="p-6">
                                    @csrf
                                    <input type="hidden" name="form_id" value="stok-keluar-{{ $product->id }}">
                                    <h3 class="text-lg font-medium text-gray-900 mb-1">Stok Keluar</h3>
                                    <p class="text-sm text-gray-500 mb-4">{{ $product->name }} — untuk barang rusak, hilang, atau kadaluarsa.</p>

                                    <x-input-label value="Qty Keluar (satuan dasar: {{ $product->baseUnit->unit_name ?? '-' }})" />
                                    <x-text-input type="number" step="0.001" min="0.001" max="{{ (float) $product->stock }}"
                                                  name="quantity" value="{{ old('form_id') === 'stok-keluar-' . $product->id ? old('quantity') : '' }}"
                                                  class="mt-1 block w-full" required />
                                    <p class="mt-1 text-xs text-gray-400">Stok saat ini: {{ rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}</p>

                                    <div class="mt-3">
                                        <x-input-label value="Keterangan (wajib)" />
                                        <textarea name="note" rows="2" required
                                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                  placeholder="Contoh: 2 pcs pecah saat penataan rak">{{ old('form_id') === 'stok-keluar-' . $product->id ? old('note') : '' }}</textarea>
                                    </div>

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-danger-button>Catat Stok Keluar</x-danger-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Mutasi -->
                            <x-modal.modal name="stok-mutasi-{{ $product->id }}">
                                <form method="POST" action="{{ route('gudang.stok.mutasi', $product) }}" class="p-6">
                                    @csrf
                                    <input type="hidden" name="form_id" value="stok-mutasi-{{ $product->id }}">
                                    <h3 class="text-lg font-medium text-gray-900 mb-1">Mutasi Stok</h3>
                                    <p class="text-sm text-gray-500 mb-4">
                                        {{ $product->name }} — fondasi untuk transfer antar cabang nanti; untuk saat ini
                                        mencatat barang yang keluar dari toko ini karena dipindahkan.
                                    </p>

                                    <x-input-label value="Qty Mutasi (satuan dasar: {{ $product->baseUnit->unit_name ?? '-' }})" />
                                    <x-text-input type="number" step="0.001" min="0.001" max="{{ (float) $product->stock }}"
                                                  name="quantity" value="{{ old('form_id') === 'stok-mutasi-' . $product->id ? old('quantity') : '' }}"
                                                  class="mt-1 block w-full" required />
                                    <p class="mt-1 text-xs text-gray-400">Stok saat ini: {{ rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}</p>

                                    <div class="mt-3">
                                        <x-input-label value="Keterangan (wajib)" />
                                        <textarea name="note" rows="2" required
                                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                  placeholder="Contoh: dipindahkan ke gudang cabang B">{{ old('form_id') === 'stok-mutasi-' . $product->id ? old('note') : '' }}</textarea>
                                    </div>

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Catat Mutasi</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Opname -->
                            <x-modal.modal name="stok-opname-{{ $product->id }}">
                                <form method="POST" action="{{ route('gudang.stok.opname', $product) }}" class="p-6">
                                    @csrf
                                    <input type="hidden" name="form_id" value="stok-opname-{{ $product->id }}">
                                    <h3 class="text-lg font-medium text-gray-900 mb-1">Stok Opname</h3>
                                    <p class="text-sm text-gray-500 mb-4">
                                        {{ $product->name }} — masukkan hasil hitung fisik, sistem otomatis hitung selisihnya.
                                    </p>

                                    <x-input-label value="Stok Fisik Hasil Hitung (satuan dasar: {{ $product->baseUnit->unit_name ?? '-' }})" />
                                    @php $oldPhysical = old('form_id') === 'stok-opname-' . $product->id ? old('physical_stock') : null; @endphp
                                    <x-text-input type="number" step="0.001" min="0" name="physical_stock"
                                                  value="{{ $oldPhysical ?? rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}"
                                                  class="mt-1 block w-full" required />
                                    <p class="mt-1 text-xs text-gray-400">Stok menurut sistem: {{ rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}</p>

                                    <div class="mt-3">
                                        <x-input-label value="Keterangan (opsional)" />
                                        <textarea name="note" rows="2"
                                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                  placeholder="Contoh: opname rutin akhir bulan">{{ old('form_id') === 'stok-opname-' . $product->id ? old('note') : '' }}</textarea>
                                    </div>

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Simpan Hasil Opname</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-gray-400">Belum ada produk yang cocok dengan filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $products->links() }}</div>
        </div>
    </div>

    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>
