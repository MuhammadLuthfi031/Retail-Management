<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('gudang.pembelian.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar penerimaan</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Terima Barang — {{ $po->po_number }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            @error('received')
                <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
            @enderror

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <div class="text-xs text-gray-400 uppercase">Supplier</div>
                    <div class="font-medium text-gray-900">{{ $po->supplier->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 uppercase">Target Terima</div>
                    <div class="font-medium text-gray-900">{{ $po->expected_date?->format('d M Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 uppercase">Catatan PO</div>
                    <div class="font-medium text-gray-900">{{ $po->note ?: '—' }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('gudang.pembelian.store', $po) }}">
                @csrf

                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Satuan</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Dipesan</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Sudah Diterima</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Sisa</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Terima Sekarang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($po->items as $item)
                                @php $remaining = $item->remainingQuantity(); @endphp
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $item->product->name }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $item->productUnit->unit_name }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ rtrim(rtrim(number_format((float) $item->quantity_ordered, 3, '.', ''), '0'), '.') }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ rtrim(rtrim(number_format((float) $item->quantity_received, 3, '.', ''), '0'), '.') }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($remaining > 0)
                                            <input type="number" step="0.001" min="0" max="{{ $remaining }}"
                                                   name="received[{{ $item->id }}]" value="{{ old('received.' . $item->id, '') }}"
                                                   placeholder="0" class="w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-indigo-500 focus:ring-indigo-500">
                                        @else
                                            <span class="text-xs text-emerald-600 font-medium">Lengkap</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end mt-4">
                    <x-primary-button>Konfirmasi Penerimaan</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
