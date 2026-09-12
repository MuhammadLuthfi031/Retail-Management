@php
    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
    $paymentColor = [
        'cash' => 'bg-emerald-100 text-emerald-700',
        'debit' => 'bg-blue-100 text-blue-700',
        'qris' => 'bg-purple-100 text-purple-700',
        'transfer' => 'bg-amber-100 text-amber-700',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Riwayat Transaksi Saya</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Dari Tanggal</label>
                    <input type="date" name="dari" value="{{ $filters['dari'] ?? '' }}"
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Sampai Tanggal</label>
                    <input type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}"
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">Filter</button>
                @if (request()->anyFilled(['dari', 'sampai']))
                    <a href="{{ route('kasir.riwayat') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Reset</a>
                @endif
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. Invoice</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Bayar</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($transactions as $trx)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $trx->invoice_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $trx->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3 text-center text-gray-500">{{ $trx->details_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $paymentColor[$trx->payment_method] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $paymentLabel[$trx->payment_method] ?? $trx->payment_method }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-900 font-medium">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('kasir.riwayat.struk', $trx) }}" target="_blank"
                                       class="text-indigo-600 hover:text-indigo-900 font-medium">Cetak Ulang</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada transaksi{{ request()->anyFilled(['dari', 'sampai']) ? ' yang cocok dengan filter ini' : '' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $transactions->links() }}</div>
        </div>
    </div>
</x-app-layout>
