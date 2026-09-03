@php
    $typeLabel = [
        'in' => 'Masuk',
        'out' => 'Keluar',
        'mutation' => 'Mutasi',
        'adjustment' => 'Opname',
        'sale' => 'Penjualan',
    ];
    $typeColor = [
        'in' => 'bg-emerald-100 text-emerald-700',
        'out' => 'bg-red-100 text-red-700',
        'mutation' => 'bg-blue-100 text-blue-700',
        'adjustment' => 'bg-amber-100 text-amber-700',
        'sale' => 'bg-purple-100 text-purple-700',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('gudang.stok.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar stok</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Riwayat Stok — {{ $product->name }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-alert />

            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-wrap items-center gap-4 text-sm">
                <div>
                    <span class="text-xs text-gray-400 uppercase block">Stok Saat Ini</span>
                    <span class="font-semibold text-gray-900">
                        {{ rtrim(rtrim(number_format((float) $product->stock, 3, '.', ''), '0'), '.') }}
                        {{ $product->baseUnit->unit_name ?? '' }}
                    </span>
                </div>
                <div>
                    <span class="text-xs text-gray-400 uppercase block">Harga Pokok Rata-Rata</span>
                    <span class="font-semibold text-gray-900">Rp {{ number_format($product->average_cost, 0, ',', '.') }}</span>
                </div>

                <form method="GET" class="ml-auto flex items-center gap-2">
                    <label class="text-xs text-gray-500">Filter Tipe:</label>
                    <select name="type" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        @foreach ($typeLabel as $key => $label)
                            <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Tipe</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Sebelum &rarr; Sesudah</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Catatan</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($movements as $m)
                            @php $increased = $m->stock_after > $m->stock_before; @endphp
                            <tr>
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $m->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $typeColor[$m->type] }}">
                                        {{ $typeLabel[$m->type] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium {{ $increased ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $increased ? '+' : '-' }}{{ rtrim(rtrim(number_format((float) $m->quantity, 3, '.', ''), '0'), '.') }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500 whitespace-nowrap">
                                    {{ rtrim(rtrim(number_format((float) $m->stock_before, 3, '.', ''), '0'), '.') }}
                                    &rarr;
                                    {{ rtrim(rtrim(number_format((float) $m->stock_after, 3, '.', ''), '0'), '.') }}
                                </td>
                                <td class="px-4 py-3 text-gray-500 max-w-xs">
                                    {{ $m->note }}
                                    @if ($m->reference)
                                        <div class="text-xs text-gray-400">Ref: {{ $m->reference }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ $m->user->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">Belum ada riwayat pergerakan stok untuk produk ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $movements->links() }}</div>
        </div>
    </div>
</x-app-layout>
