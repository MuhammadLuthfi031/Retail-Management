@php
    $statusLabel = ['ordered' => 'Dipesan (belum ada barang masuk)', 'partially_received' => 'Diterima Sebagian'];
    $statusColor = ['ordered' => 'bg-blue-100 text-blue-700', 'partially_received' => 'bg-amber-100 text-amber-700'];
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

            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Cari nomor PO..."
                       class="w-full sm:w-72 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
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
                                        Konfirmasi Penerimaan
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    Tidak ada PO yang menunggu konfirmasi penerimaan saat ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $purchaseOrders->links() }}</div>
        </div>
    </div>
</x-app-layout>
