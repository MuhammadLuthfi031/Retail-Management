<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Laporan') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin.laporan._tabs')

            <x-alert />

            <!-- Ringkasan -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Nilai Inventori (stok × HPP)</div>
                    <div class="text-2xl font-semibold text-gray-900 mt-1">Rp {{ number_format($totalNilaiInventori, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Produk Stok Menipis</div>
                    <div class="text-2xl font-semibold text-red-600 mt-1">{{ $lowStockProducts->count() }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Jumlah SKU</div>
                    <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $produk->total() }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                <!-- Produk Terlaris -->
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-medium text-gray-800">Produk Terlaris</h3>
                        <form method="GET" class="flex gap-2 items-center text-xs">
                            <input type="date" name="from" value="{{ request('from', $from->toDateString()) }}" class="rounded-md border-gray-300 text-xs">
                            <span class="text-gray-400">—</span>
                            <input type="date" name="to" value="{{ request('to', $to->toDateString()) }}" class="rounded-md border-gray-300 text-xs">
                            @foreach (request()->except(['from', 'to', 'page']) as $key => $val)
                                <input type="hidden" name="{{ $key }}" value="{{ $val }}">
                            @endforeach
                            <button type="submit" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded text-gray-700">Terapkan</button>
                        </form>
                    </div>
                    <ol class="space-y-1.5 text-sm">
                        @forelse ($terlaris as $i => $row)
                            <li class="flex items-center justify-between">
                                <span class="text-gray-700">{{ $i + 1 }}. {{ $row->product_name }}</span>
                                <span class="text-gray-400 whitespace-nowrap ml-2">Rp {{ number_format($row->omzet, 0, ',', '.') }}</span>
                            </li>
                        @empty
                            <li class="text-gray-400">Belum ada penjualan di rentang ini.</li>
                        @endforelse
                    </ol>
                </div>

                <!-- Stok Menipis -->
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <h3 class="font-medium text-gray-800 mb-3">Stok Menipis</h3>
                    <ol class="space-y-1.5 text-sm max-h-64 overflow-y-auto">
                        @forelse ($lowStockProducts as $p)
                            <li class="flex items-center justify-between">
                                <span class="text-gray-700">{{ $p->name }}</span>
                                <span class="text-red-600 font-medium whitespace-nowrap ml-2">{{ $p->formatStock((float) $p->stock) }}</span>
                            </li>
                        @empty
                            <li class="text-gray-400">Tidak ada produk dengan stok menipis. 👍</li>
                        @endforelse
                    </ol>
                </div>
            </div>

            <!-- Filter tabel inventori -->
            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <input type="hidden" name="from" value="{{ request('from', $from->toDateString()) }}">
                <input type="hidden" name="to" value="{{ request('to', $to->toDateString()) }}">
                <div class="min-w-[180px]">
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
                    Hanya stok menipis
                </label>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">
                    Filter
                </button>
                <a href="{{ route('admin.laporan.stok.pdf', request()->query()) }}"
                   class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-md text-sm font-medium">
                    Export PDF
                </a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kategori</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Stok</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">HPP / Satuan Dasar</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Nilai Inventori</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($produk as $p)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $p->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $p->category->name }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                        'bg-red-100 text-red-700' => $p->isLowStock(),
                                        'bg-gray-100 text-gray-700' => ! $p->isLowStock(),
                                    ])>{{ $p->formatStock((float) $p->stock) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">Rp {{ number_format($p->average_cost, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($p->stock * $p->average_cost, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada produk yang cocok dengan filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $produk->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
