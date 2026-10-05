@php
    $statusBadge = [
        'pending' => ['Menunggu', 'bg-amber-100 text-amber-700'],
        'settled' => ['Selesai', 'bg-emerald-100 text-emerald-700'],
    ];
    $settlementLabel = ['refund' => 'Refund', 'credit' => 'Potong tagihan', 'replacement' => 'Barang pengganti'];
    $tabs = ['menunggu' => 'Menunggu penyelesaian', 'selesai' => 'Selesai', 'semua' => 'Semua'];
    $chipOn = 'bg-indigo-600 border-indigo-600 text-white';
    $chipOff = 'bg-white border-gray-300 text-gray-600 hover:border-indigo-400';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Retur ke Supplier</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-alert />

            <div class="bg-white shadow-sm sm:rounded-lg p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <div class="text-xs text-gray-400 uppercase">Belum diselesaikan supplier</div>
                    <div class="text-lg font-bold text-gray-900">
                        Rp {{ number_format($pendingValue, 0, ',', '.') }}
                        <span class="text-sm font-normal text-gray-500">&middot; {{ $pendingCount }} retur</span>
                    </div>
                </div>
                <p class="text-xs text-gray-400 sm:max-w-xs">Retur dibuat dari halaman detail PO (tombol "Retur Barang"). Di sini Anda menandai retur selesai setelah refund atau potong tagihan terjadi.</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 sm:items-center justify-between">
                <div class="flex gap-2 overflow-x-auto pb-1">
                    @foreach ($tabs as $key => $label)
                        <a href="{{ route('admin.retur-pembelian.index', array_filter(['tab' => $key, 'cari' => $cari])) }}"
                           class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-sm border {{ $tab === $key ? $chipOn : $chipOff }}">{{ $label }}</a>
                    @endforeach
                </div>
                <form method="GET" class="flex gap-2">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="text" name="cari" value="{{ $cari }}" maxlength="50" placeholder="No. retur / No. PO"
                           class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="submit" class="min-h-[40px] px-4 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">Cari</button>
                </form>
            </div>

            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. Retur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">PO / Supplier</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Nilai</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($returns as $r)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $r->return_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $r->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $r->purchaseOrder->po_number }} &middot; {{ $r->purchaseOrder->supplier->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-center text-gray-500">{{ $r->items_count }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900 whitespace-nowrap">Rp {{ number_format($r->total_value, 0, ',', '.') }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusBadge[$r->status][1] }}">{{ $statusBadge[$r->status][0] }}@if ($r->settlement_type) &middot; {{ $settlementLabel[$r->settlement_type] }}@endif</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.retur-pembelian.show', $r) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400">Tidak ada retur{{ $tab === 'menunggu' ? ' yang menunggu penyelesaian' : '' }}{{ $cari !== '' ? ' yang cocok dengan pencarian' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden space-y-2.5">
                @forelse ($returns as $r)
                    <a href="{{ route('admin.retur-pembelian.show', $r) }}" class="block bg-white border border-gray-200 rounded-xl p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 text-sm truncate">{{ $r->return_number }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">{{ $r->created_at->format('d M Y, H:i') }} &middot; {{ $r->items_count }} item</div>
                                <div class="text-xs text-gray-500 mt-0.5 truncate">{{ $r->purchaseOrder->po_number }} &middot; {{ $r->purchaseOrder->supplier->name ?? '—' }}</div>
                            </div>
                            <div class="text-right flex-none">
                                <div class="text-base font-bold text-gray-900 whitespace-nowrap">Rp {{ number_format($r->total_value, 0, ',', '.') }}</div>
                                <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $statusBadge[$r->status][1] }}">{{ $statusBadge[$r->status][0] }}</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="bg-white border border-gray-200 rounded-xl py-10 text-center text-gray-400 text-sm">Tidak ada retur{{ $tab === 'menunggu' ? ' yang menunggu penyelesaian' : '' }}{{ $cari !== '' ? ' yang cocok dengan pencarian' : '' }}.</div>
                @endforelse
            </div>

            <div class="mt-4">{{ $returns->links() }}</div>
        </div>
    </div>
</x-app-layout>