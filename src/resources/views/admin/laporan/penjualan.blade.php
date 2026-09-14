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
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Kasir</label>
                    <select name="user_id" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        @foreach ($kasirList as $k)
                            <option value="{{ $k->id }}" @selected(request('user_id') == $k->id)>{{ $k->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Metode Bayar</label>
                    <select name="payment_method" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        <option value="cash" @selected(request('payment_method') === 'cash')>Cash</option>
                        <option value="debit" @selected(request('payment_method') === 'debit')>Debit</option>
                        <option value="qris" @selected(request('payment_method') === 'qris')>QRIS</option>
                        <option value="transfer" @selected(request('payment_method') === 'transfer')>Transfer</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">
                    Filter
                </button>
                <a href="{{ route('admin.laporan.penjualan.pdf', request()->query()) }}"
                   class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 rounded-md text-sm font-medium">
                    Export PDF
                </a>
            </form>

            <!-- Ringkasan -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Total Omzet</div>
                    <div class="text-2xl font-semibold text-gray-900 mt-1">Rp {{ number_format($total_omzet, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Jumlah Transaksi</div>
                    <div class="text-2xl font-semibold text-gray-900 mt-1">{{ number_format($jumlah_transaksi, 0, ',', '.') }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <div class="text-xs text-gray-400 uppercase tracking-wider">Rata-rata / Transaksi</div>
                    <div class="text-2xl font-semibold text-gray-900 mt-1">Rp {{ number_format($rata_rata, 0, ',', '.') }}</div>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kasir</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Metode Bayar</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($transaksi as $trx)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $trx->invoice_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $trx->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $trx->user->name }}</td>
                                <td class="px-4 py-3 text-center text-gray-500">{{ ucfirst($trx->payment_method) }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada transaksi pada rentang & filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $transaksi->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
