@php
    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
    $stateBadge = [
        'partial' => ['Sudah diretur sebagian', 'bg-amber-100 text-amber-700'],
        'full' => ['Sudah diretur penuh', 'bg-gray-200 text-gray-600'],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Retur Penjualan</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            <!-- Cari transaksi yang akan diretur -->
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <h3 class="font-semibold text-gray-900 mb-1">Proses retur baru</h3>
                <p class="text-sm text-gray-500 mb-3">Cari transaksi dari kasir mana pun berdasarkan nomor invoice (boleh sebagian, mis. <span class="font-mono">0003</span>). Untuk transaksi Anda sendiri, retur juga bisa dilakukan dari Riwayat Transaksi.</p>
                <form method="GET" class="flex flex-col sm:flex-row gap-2">
                    <input type="text" name="cari" value="{{ $cari }}" maxlength="50" placeholder="Nomor invoice, mis. INV-20261003-0003"
                           class="flex-1 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="submit" class="min-h-[44px] px-5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">Cari</button>
                    @if ($cari !== '')
                        <a href="{{ route('admin.retur-penjualan.index') }}" class="min-h-[44px] inline-flex items-center justify-center px-4 text-sm font-medium text-gray-500 hover:text-gray-700">Reset</a>
                    @endif
                </form>

                @if ($hasil !== null)
                    <div class="mt-4 divide-y divide-gray-100 border border-gray-200 rounded-lg overflow-hidden">
                        @forelse ($hasil as $trx)
                            @php $state = $trx->returnState(); @endphp
                            <div class="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 text-sm">{{ $trx->invoice_number }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        {{ $trx->created_at->format('d M Y, H:i') }} &middot; {{ $trx->user->name }} &middot;
                                        {{ $paymentLabel[$trx->payment_method] ?? $trx->payment_method }} &middot;
                                        Rp {{ number_format($trx->grand_total, 0, ',', '.') }}
                                    </div>
                                    @if (isset($stateBadge[$state]))
                                        <span class="inline-flex mt-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stateBadge[$state][1] }}">{{ $stateBadge[$state][0] }}</span>
                                    @endif
                                </div>
                                @if ($state === 'full')
                                    <span class="text-xs text-gray-400">Tidak ada sisa untuk diretur</span>
                                @else
                                    <button type="button"
                                            data-retur-open="{{ route('kasir.riwayat.retur.form', $trx) }}"
                                            data-retur-invoice="{{ $trx->invoice_number }}"
                                            class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100">
                                        Proses Retur
                                    </button>
                                @endif
                            </div>
                        @empty
                            <div class="p-6 text-center text-sm text-gray-400">Tidak ada transaksi selesai dengan nomor invoice tersebut.</div>
                        @endforelse
                    </div>
                @endif
            </div>

            <!-- Riwayat retur -->
            <div>
                <h3 class="font-semibold text-gray-900 mb-2 px-4 sm:px-0">Riwayat retur</h3>

                <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. Retur</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Invoice Asal</th>
                                <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Diproses</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Refund</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($returns as $r)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $r->return_number }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $r->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $r->transaction->invoice_number }}</td>
                                    <td class="px-4 py-3 text-center text-gray-500">{{ $r->items_count }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $r->user->name }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($r->total_refund, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.retur-penjualan.show', $r) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">Detail</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400">Belum ada retur.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="md:hidden space-y-2.5">
                    @forelse ($returns as $r)
                        <a href="{{ route('admin.retur-penjualan.show', $r) }}" class="block bg-white border border-gray-200 rounded-xl p-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 text-sm truncate">{{ $r->return_number }}</div>
                                    <div class="text-xs text-gray-400 mt-0.5">{{ $r->created_at->format('d M Y, H:i') }} &middot; {{ $r->items_count }} item</div>
                                    <div class="text-xs text-gray-500 mt-0.5">dari {{ $r->transaction->invoice_number }}</div>
                                </div>
                                <div class="text-base font-bold text-gray-900 whitespace-nowrap">Rp {{ number_format($r->total_refund, 0, ',', '.') }}</div>
                            </div>
                        </a>
                    @empty
                        <div class="bg-white border border-gray-200 rounded-xl py-10 text-center text-gray-400 text-sm">Belum ada retur.</div>
                    @endforelse
                </div>

                <div class="mt-4">{{ $returns->links() }}</div>
            </div>
        </div>
    </div>

    @include('kasir.partials.retur-modal')
</x-app-layout>