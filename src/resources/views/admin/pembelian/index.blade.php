@php
    $statusLabel = [
        'draft' => 'Draft',
        'ordered' => 'Dipesan',
        'partially_received' => 'Diterima Sebagian',
        'received' => 'Diterima Lengkap',
        'cancelled' => 'Dibatalkan',
    ];
    $statusColor = [
        'draft' => 'bg-gray-100 text-gray-600',
        'ordered' => 'bg-blue-100 text-blue-700',
        'partially_received' => 'bg-amber-100 text-amber-700',
        'received' => 'bg-emerald-100 text-emerald-700',
        'cancelled' => 'bg-red-100 text-red-700',
    ];
    $paymentLabel = ['unpaid' => 'Belum Lunas', 'partial' => 'Sebagian', 'paid' => 'Lunas'];
    $paymentColor = [
        'unpaid' => 'bg-red-50 text-red-600',
        'partial' => 'bg-amber-50 text-amber-600',
        'paid' => 'bg-emerald-50 text-emerald-600',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Purchase Order') }}
            </h2>
            <button type="button" data-modal-open="create-po"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                + Buat PO
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Filter -->
            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Cari No. PO</label>
                    <input type="text" name="search" value="{{ request('search') }}"
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Supplier</label>
                    <select name="supplier_id" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua Supplier</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        @foreach ($statusLabel as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">Filter</button>
                @if (request()->anyFilled(['search', 'supplier_id', 'status']))
                    <a href="{{ route('admin.pembelian.index') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Reset</a>
                @endif
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. PO</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tgl Pesan</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Bayar</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($purchaseOrders as $po)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $po->po_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $po->supplier->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $po->order_date->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColor[$po->status] }}">
                                        {{ $statusLabel[$po->status] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $paymentColor[$po->payment_status] }}">
                                        {{ $paymentLabel[$po->payment_status] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-900">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.pembelian.show', $po) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-gray-400">Belum ada PO yang cocok dengan filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $purchaseOrders->links() }}</div>
        </div>
    </div>

    <!-- Modal Create -->
    <x-modal.modal name="create-po" maxWidth="2xl">
        <form method="POST" action="{{ route('admin.pembelian.store') }}" class="p-6">
            @csrf
            <h3 class="text-lg font-medium text-gray-900 mb-4">Buat Purchase Order</h3>

            @include('admin.pembelian._form', ['po' => null, 'formId' => 'create-po', 'suppliers' => $suppliers, 'products' => $products])

            <div class="flex justify-end gap-2 mt-6">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan sebagai Draft</x-primary-button>
            </div>
        </form>
    </x-modal.modal>

    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>
