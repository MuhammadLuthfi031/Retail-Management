@php
    $statusLabel = ['ordered' => 'Dipesan (belum ada barang masuk)', 'partially_received' => 'Diterima Sebagian', 'received' => 'Diterima Lengkap'];
    $statusColor = ['ordered' => 'bg-blue-100 text-blue-700', 'partially_received' => 'bg-amber-100 text-amber-700', 'received' => 'bg-emerald-100 text-emerald-700'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Konfirmasi Penerimaan Barang') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <div class="flex items-center gap-1 mb-4 border-b border-gray-200">
                <a href="{{ route('gudang.pembelian.index', ['tab' => 'menunggu', 'search' => request('search')]) }}"
                   class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $tab === 'menunggu' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Menunggu
                </a>
                <a href="{{ route('gudang.pembelian.index', ['tab' => 'selesai', 'search' => request('search')]) }}"
                   class="px-4 py-2 text-sm font-medium border-b-2 -mb-px {{ $tab === 'selesai' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Selesai
                </a>
            </div>

            <form method="GET" class="mb-4">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Cari nomor PO..."
                       class="w-full sm:w-72 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </form>

            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. PO</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Target Terima</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Jumlah Item</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($purchaseOrders as $po)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $po->po_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $po->supplier->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $po->expected_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-center text-gray-500">{{ $po->items_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColor[$po->status] }}">
                                        {{ $statusLabel[$po->status] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('gudang.pembelian.show', $po) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                        {{ $tab === 'selesai' ? 'Lihat Riwayat' : 'Konfirmasi Penerimaan' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    {{ $tab === 'selesai' ? 'Belum ada PO yang selesai diterima.' : 'Tidak ada PO yang menunggu konfirmasi penerimaan saat ini.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Kartu — mobile (<768px), menggantikan tabel yang tadinya harus digeser horizontal -->
            <div class="md:hidden space-y-2.5">
                @forelse ($purchaseOrders as $po)
                    <a href="{{ route('gudang.pembelian.show', $po) }}" class="block bg-white border border-gray-200 rounded-xl p-3.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-900">{{ $po->po_number }}</span>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-medium {{ $statusColor[$po->status] }}">
                                {{ $statusLabel[$po->status] }}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">{{ $po->supplier->name ?? '—' }}</div>
                        <div class="flex items-center justify-between mt-2.5 text-xs">
                            <span class="text-gray-500">Target: {{ $po->expected_date?->format('d M Y') ?? '—' }} &middot; {{ $po->items_count }} item</span>
                            <span class="text-indigo-600 font-semibold whitespace-nowrap">
                                {{ $tab === 'selesai' ? 'Lihat Riwayat' : 'Konfirmasi' }} &rarr;
                            </span>
                        </div>
                    </a>
                @empty
                    <div class="bg-white border border-gray-200 rounded-xl py-10 text-center text-gray-400 text-sm">
                        {{ $tab === 'selesai' ? 'Belum ada PO yang selesai diterima.' : 'Tidak ada PO yang menunggu konfirmasi penerimaan saat ini.' }}
                    </div>
                @endforelse
            </div>

            <div class="mt-4">{{ $purchaseOrders->links() }}</div>
        </div>
    </div>
</x-app-layout>