<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('gudang.produk.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar produk</a>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">{{ $product->name }}</h2>
            </div>
            <span @class([
                'inline-flex px-3 py-1 rounded-full text-xs font-medium',
                'bg-emerald-100 text-emerald-700' => $product->is_active,
                'bg-gray-100 text-gray-500' => ! $product->is_active,
            ])>
                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            <!-- Info utama -->
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-col sm:flex-row gap-6">
                    <div class="shrink-0">
                        @if ($product->image)
                            <img src="{{ Storage::url($product->image) }}" class="w-32 h-32 rounded-lg object-cover border border-gray-200">
                        @else
                            <div class="w-32 h-32 rounded-lg bg-gray-100 flex items-center justify-center text-gray-300">
                                <x-icon name="cube" class="w-10 h-10" />
                            </div>
                        @endif
                    </div>

                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                        <div>
                            <div class="text-xs text-gray-400 uppercase">SKU</div>
                            <div class="font-medium text-gray-900">{{ $product->sku }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Kategori</div>
                            <div class="font-medium text-gray-900">{{ $product->category->name }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Cara Jual</div>
                            <div class="font-medium text-gray-900">{{ $product->trackingModeLabel() }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Stok Saat Ini</div>
                            <div @class([
                                'font-medium',
                                'text-red-600' => $product->isLowStock(),
                                'text-gray-900' => ! $product->isLowStock(),
                            ])>
                                {{ $product->formatStock((float) $product->stock) }}
                                @if ($product->isLowStock())
                                    <span class="text-xs font-normal text-red-500">(menipis)</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Ambang Batas Menipis</div>
                            <div class="font-medium text-gray-900">
                                {{ rtrim(rtrim(number_format((float) $product->min_stock, 3, '.', ''), '0'), '.') }}
                                {{ $product->baseUnit->unit_name ?? '' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Harga Pokok Rata-Rata</div>
                            <div class="font-medium text-gray-900">
                                Rp {{ number_format($product->average_cost, 0, ',', '.') }} / {{ $product->baseUnit->unit_name ?? '' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Jual Kuantitas Pecahan</div>
                            <div class="font-medium text-gray-900">{{ $product->allow_fractional_sale ? 'Ya' : 'Tidak' }}</div>
                        </div>
                        @if ($product->description)
                            <div class="sm:col-span-3">
                                <div class="text-xs text-gray-400 uppercase">Deskripsi</div>
                                <div class="text-gray-700">{{ $product->description }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 pt-4 mt-4 border-t border-gray-100">
                    <button type="button" data-modal-open="edit-product"
                            class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-gray-100 text-gray-700 hover:bg-gray-200">
                        Edit
                    </button>
                    <button type="button" data-modal-open="delete-product"
                            class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-white text-red-600 border border-red-200 hover:bg-red-50">
                        Hapus
                    </button>
                    <a href="{{ route('gudang.stok.show', $product) }}"
                       class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-white text-indigo-600 border border-indigo-200 hover:bg-indigo-50">
                        Riwayat Stok
                    </a>
                </div>
            </div>

            <!-- Satuan -->
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <div class="px-6 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-medium text-gray-700">Satuan Produk</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Nama Satuan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Isi (&rarr; dasar)</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Harga Jual</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Barcode</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Peran</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($product->units as $index => $unit)
                            @php
                                // Tampilkan "Isi" relatif ke satuan TEPAT DI BAWAHNYA (bukan
                                // langsung ke satuan dasar) — konsisten dengan cara input di
                                // form Tambah/Edit Produk. Baris dasar (paling bawah, tidak
                                // punya "next") ditampilkan trivial "1 x = 1 x".
                                $next = $product->units->get($index + 1);
                                $nextConversion = $next ? (float) $next->conversion_to_base : 1.0;
                                $relativeQty = $next ? round((float) $unit->conversion_to_base / max($nextConversion, 0.000001), 3) : 1.0;
                                $relativeUnitName = $next ? $next->unit_name : $unit->unit_name;
                                $relativeQtyText = rtrim(rtrim(number_format($relativeQty, 3, '.', ''), '0'), '.');
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $unit->unit_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">
                                    1 {{ $unit->unit_name }} = {{ $relativeQtyText }} {{ $relativeUnitName }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-900">
                                    {{ $unit->selling_price ? 'Rp ' . number_format($unit->selling_price, 0, ',', '.') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $unit->barcode ?: '—' }}</td>
                                <td class="px-4 py-3 text-center space-x-1">
                                    @if ($unit->is_base_unit)
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">Dasar</span>
                                    @endif
                                    @if ($unit->is_purchase_unit)
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">Beli</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-400">Belum ada satuan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <x-modal.modal name="edit-product" maxWidth="2xl">
        <form method="POST" action="{{ route('gudang.produk.update', $product) }}" enctype="multipart/form-data" class="p-6">
            @csrf
            @method('PUT')
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Produk</h3>

            @include('gudang.produk._form', ['product' => $product, 'formId' => 'edit-product', 'categories' => $categories])

            <div class="flex justify-end gap-2 mt-6">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan Perubahan</x-primary-button>
            </div>
        </form>
    </x-modal.modal>

    <!-- Modal Delete -->
    <x-modal.modal name="delete-product">
        <form method="POST" action="{{ route('gudang.produk.destroy', $product) }}" class="p-6">
            @csrf
            @method('DELETE')
            <h3 class="text-lg font-medium text-gray-900 mb-2">Hapus Produk?</h3>
            <p class="text-sm text-gray-500 mb-4">
                Produk "<strong>{{ $product->name }}</strong>" beserta semua data satuannya akan
                dihapus permanen. Tindakan ini tidak bisa dibatalkan.
            </p>
            <div class="flex justify-end gap-2">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-danger-button>Ya, Hapus</x-danger-button>
            </div>
        </form>
    </x-modal.modal>

    {{-- Sama seperti modul lain: buka lagi modal yang tadi disubmit kalau validasi
         edit gagal, supaya isian & error-nya tidak hilang begitu saja. --}}
    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>