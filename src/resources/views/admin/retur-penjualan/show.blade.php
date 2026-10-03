@php
    use App\Support\Number;

    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
    $conditionLabel = ['resellable' => ['Layak jual (masuk stok)', 'bg-emerald-100 text-emerald-700'], 'damaged' => ['Rusak (tidak masuk stok)', 'bg-red-100 text-red-700']];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.retur-penjualan.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar retur</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Retur {{ $return->return_number }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-alert />

            <div class="bg-white shadow-sm sm:rounded-lg p-4 text-sm grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><div class="text-xs text-gray-400">Tanggal retur</div>{{ $return->created_at->format('d M Y, H:i') }}</div>
                <div><div class="text-xs text-gray-400">Invoice asal</div>
                    <a href="{{ route('kasir.riwayat.struk', $return->transaction) }}" target="_blank" class="text-indigo-600 hover:underline">{{ $return->transaction->invoice_number }}</a>
                </div>
                <div><div class="text-xs text-gray-400">Diproses oleh</div>{{ $return->user->name }}</div>
                <div><div class="text-xs text-gray-400">Dikembalikan lewat</div>{{ $paymentLabel[$return->refund_method] ?? $return->refund_method }}</div>
                <div class="col-span-2 md:col-span-4"><div class="text-xs text-gray-400">Alasan</div>{{ $return->reason }}</div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Barang</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kondisi</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Refund</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($return->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-gray-900">{{ $item->product_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">{{ Number::trim($item->quantity) }} {{ $item->unit_name }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $conditionLabel[$item->condition][1] }}">{{ $conditionLabel[$item->condition][0] }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900 whitespace-nowrap">Rp {{ number_format($item->refund_amount, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">Total dikembalikan</td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900 whitespace-nowrap">Rp {{ number_format($return->total_refund, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>