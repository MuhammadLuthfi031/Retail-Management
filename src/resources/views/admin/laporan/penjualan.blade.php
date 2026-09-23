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

            <!-- Filter — desktop/tablet (≥768px), tidak berubah -->
            <form method="GET" class="hidden md:flex mb-4 bg-white p-4 rounded-lg shadow-sm flex-wrap gap-3 items-end">
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

            <!-- Filter — mobile (<768px): dilipat (accordion), field & nama input SAMA
                 persis dengan versi desktop di atas supaya query string yang dihasilkan
                 identik, cuma tampilannya yang beda. -->
            <div class="md:hidden mb-4">
                <button type="button" data-collapse-toggle="filter-laporan-mobile"
                        class="w-full flex items-center justify-between bg-white border border-gray-200 rounded-lg px-4 py-3 text-sm font-semibold text-gray-700">
                    <span>Filter &amp; Rentang Tanggal</span>
                    <x-icon name="chevron-down" class="w-4 h-4 text-gray-400 transition-transform" data-collapse-chevron="filter-laporan-mobile" />
                </button>
                <form method="GET" id="filter-laporan-mobile" class="hidden mt-2 bg-white border border-gray-200 rounded-lg p-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Dari Tanggal</label>
                            <input type="date" name="from" value="{{ request('from', $from->toDateString()) }}"
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Sampai Tanggal</label>
                            <input type="date" name="to" value="{{ request('to', $to->toDateString()) }}"
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Kasir</label>
                        <select name="user_id" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            @foreach ($kasirList as $k)
                                <option value="{{ $k->id }}" @selected(request('user_id') == $k->id)>{{ $k->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Metode Bayar</label>
                        <select name="payment_method" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua</option>
                            <option value="cash" @selected(request('payment_method') === 'cash')>Cash</option>
                            <option value="debit" @selected(request('payment_method') === 'debit')>Debit</option>
                            <option value="qris" @selected(request('payment_method') === 'qris')>QRIS</option>
                            <option value="transfer" @selected(request('payment_method') === 'transfer')>Transfer</option>
                        </select>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">
                            Terapkan Filter
                        </button>
                        <a href="{{ route('admin.laporan.penjualan.pdf', request()->query()) }}"
                           class="px-4 py-2.5 bg-red-50 hover:bg-red-100 text-red-700 rounded-md text-sm font-medium">
                            PDF
                        </a>
                    </div>
                </form>
            </div>

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

            <!-- Tabel — desktop/tablet (≥768px) -->
            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
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

            <!-- Kartu — mobile (<768px), menggantikan tabel yang tadinya harus digeser horizontal -->
            <div class="md:hidden space-y-2.5">
                @forelse ($transaksi as $trx)
                    <div class="bg-white border border-gray-200 rounded-xl p-3.5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-gray-900">{{ $trx->invoice_number }}</span>
                            <span class="text-sm font-bold text-gray-900 whitespace-nowrap">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center flex-wrap gap-x-2 gap-y-1.5 mt-2 text-xs text-gray-500">
                            <span>{{ $trx->user->name }}</span>
                            <span>&middot;</span>
                            <span>{{ $trx->created_at->format('d/m/Y H:i') }}</span>
                            <span @class([
                                'px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide',
                                'bg-emerald-100 text-emerald-700' => $trx->payment_method === 'cash',
                                'bg-purple-100 text-purple-700' => $trx->payment_method === 'qris',
                                'bg-blue-100 text-blue-700' => $trx->payment_method === 'debit',
                                'bg-amber-100 text-amber-700' => $trx->payment_method === 'transfer',
                            ])>
                                {{ $trx->payment_method }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-gray-200 rounded-xl py-10 text-center text-gray-400 text-sm">
                        Belum ada transaksi pada rentang &amp; filter ini.
                    </div>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $transaksi->links() }}
            </div>
        </div>
    </div>
</x-app-layout>