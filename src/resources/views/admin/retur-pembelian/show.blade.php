@php
    use App\Support\Number;

    $statusBadge = [
        'pending' => ['Menunggu penyelesaian', 'bg-amber-100 text-amber-700'],
        'settled' => ['Selesai', 'bg-emerald-100 text-emerald-700'],
    ];
    $settlementLabel = ['refund' => 'Refund dari supplier', 'credit' => 'Potong tagihan', 'replacement' => 'Barang pengganti'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <a href="{{ route('admin.retur-pembelian.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar retur</a>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Retur {{ $return->return_number }}</h2>
            </div>
            <span class="inline-flex px-3 py-1 rounded-full text-xs font-medium {{ $statusBadge[$return->status][1] }}">{{ $statusBadge[$return->status][0] }}</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-alert />

            <div class="bg-white shadow-sm sm:rounded-lg p-4 text-sm grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><div class="text-xs text-gray-400">Tanggal retur</div>{{ $return->created_at->format('d M Y, H:i') }}</div>
                <div><div class="text-xs text-gray-400">PO asal</div>
                    <a href="{{ route('admin.pembelian.show', $return->purchaseOrder) }}" class="text-indigo-600 hover:underline">{{ $return->purchaseOrder->po_number }}</a>
                </div>
                <div><div class="text-xs text-gray-400">Supplier</div>{{ $return->purchaseOrder->supplier->name ?? '—' }}</div>
                <div><div class="text-xs text-gray-400">Dibuat oleh</div>{{ $return->user->name }}</div>
                <div class="col-span-2 md:col-span-4"><div class="text-xs text-gray-400">Alasan</div>{{ $return->reason }}</div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Barang</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Nilai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($return->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-gray-900">{{ $item->product_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">{{ Number::trim($item->quantity) }} {{ $item->unit_name }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900 whitespace-nowrap">Rp {{ number_format($item->value, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="2" class="px-4 py-3 text-right font-semibold text-gray-700">Total nilai retur</td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900 whitespace-nowrap">Rp {{ number_format($return->total_value, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-xs text-gray-400 px-4 sm:px-0">Nilai dihitung dari harga beli di PO (bukan harga pokok rata-rata) dan tidak mengubah total PO.</p>

            @if ($return->status === 'pending')
                <form method="POST" action="{{ route('admin.retur-pembelian.settle', $return) }}"
                      onsubmit="return confirm('Tandai retur ini selesai? Tindakan ini tidak bisa dibatalkan.');"
                      class="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
                    @csrf
                    @method('PUT')
                    <h3 class="font-semibold text-gray-900">Selesaikan retur</h3>
                    <p class="text-xs text-gray-500">Tandai selesai setelah supplier mengembalikan uang atau Anda memotong tagihan ke supplier ini.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Jenis penyelesaian</label>
                            <select name="settlement_type" required
                                    class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="" disabled @selected(! old('settlement_type'))>Pilih jenis...</option>
                                <option value="refund" @selected(old('settlement_type') === 'refund')>Refund — uang dikembalikan supplier</option>
                                <option value="credit" @selected(old('settlement_type') === 'credit')>Potong tagihan — mengurangi hutang ke supplier</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Catatan (opsional)</label>
                            <input type="text" name="settlement_note" value="{{ old('settlement_note') }}" maxlength="255"
                                   placeholder="mis. transfer 12 Okt, nota kredit no. 123"
                                   class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="min-h-[44px] px-6 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-semibold">Tandai Selesai</button>
                    </div>
                </form>
            @else
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-900">
                    <div class="font-semibold">Selesai &middot; {{ $settlementLabel[$return->settlement_type] }}</div>
                    <div class="text-xs mt-1 text-emerald-800">
                        Oleh {{ $return->settledBy->name ?? '—' }} &middot; {{ $return->settled_at?->format('d M Y, H:i') }}
                    </div>
                    @if ($return->settlement_note)
                        <div class="text-xs mt-1 text-emerald-800">Catatan: {{ $return->settlement_note }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>