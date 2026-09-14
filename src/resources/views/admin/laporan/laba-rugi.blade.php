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

            @if ($adaDataLegacy)
                <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    Sebagian transaksi di rentang ini dibuat sebelum sistem mencatat snapshot harga pokok per baris.
                    Untuk baris tersebut, laporan memakai harga pokok rata-rata TERKINI sebagai perkiraan
                    (bukan harga pokok asli saat transaksi itu terjadi).
                </div>
            @endif

            <!-- Filter -->
            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Dari Tanggal</label>
                    <input type="date" name="from" value="{{ request('from', $from->toDateString()) }}"
                           class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Sampai Tanggal</label>
                    <input type="date" name="to" value="{{ request('to', $to->toDateString()) }}"
                           class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">
                    Filter
                </button>
                <a href="{{ route('admin.laporan.laba-rugi.pdf', request()->query()) }}"
                   class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-md text-sm font-medium">
                    Export PDF
                </a>
            </form>

            <!-- Ringkasan -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Total Penjualan</div>
                    <div class="text-xl font-semibold text-gray-900 mt-1">Rp {{ number_format($totalOmzet, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Total HPP</div>
                    <div class="text-xl font-semibold text-gray-900 mt-1">Rp {{ number_format($totalHpp, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Laba Kotor</div>
                    <div @class([
                        'text-xl font-semibold mt-1',
                        'text-emerald-600' => $totalLaba >= 0,
                        'text-red-600' => $totalLaba < 0,
                    ])>Rp {{ number_format($totalLaba, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Margin</div>
                    <div class="text-xl font-semibold text-gray-900 mt-1">{{ $margin }}%</div>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Qty Terjual*</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Omzet</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">HPP</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Laba</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($perProduk as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $row->product_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ rtrim(rtrim(number_format($row->qty_terjual, 3, ',', '.'), '0'), ',') }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">Rp {{ number_format($row->omzet, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">Rp {{ number_format($row->hpp, 0, ',', '.') }}</td>
                                <td @class([
                                    'px-4 py-3 text-right font-medium',
                                    'text-emerald-600' => $row->laba >= 0,
                                    'text-red-600' => $row->laba < 0,
                                ])>Rp {{ number_format($row->laba, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ $row->margin }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada penjualan pada rentang tanggal ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400 mt-2">*Qty dalam satuan dasar produk masing-masing.</p>
        </div>
    </div>
</x-app-layout>
