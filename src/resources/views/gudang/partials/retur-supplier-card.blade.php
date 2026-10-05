@php
    use App\Support\Number;

    $statusBadge = [
        'pending' => ['Menunggu penyelesaian', 'bg-amber-100 text-amber-700'],
        'settled' => ['Selesai', 'bg-emerald-100 text-emerald-700'],
    ];
    $settlementLabel = ['refund' => 'Refund', 'credit' => 'Potong tagihan', 'replacement' => 'Barang pengganti'];
@endphp

{{--
    Kartu "Retur ke Supplier" di halaman detail PO (Admin & Gudang).
    Parameter: $po (dengan purchaseReturns.items & purchaseReturns.user ter-load), $canReturn (bool),
    $showValues (bool; true hanya untuk Admin — Gudang TIDAK boleh melihat nilai uang).
--}}
@if ($canReturn || $po->purchaseReturns->isNotEmpty())
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Retur ke Supplier</h3>
                <p class="text-xs text-gray-400 mt-0.5">Barang rusak atau salah kirim? Stok langsung berkurang saat retur disimpan.</p>
            </div>
            @if ($canReturn)
                <button type="button"
                        data-retur-open="{{ route('gudang.pembelian.retur.form', $po) }}"
                        data-retur-title="Retur ke Supplier"
                        data-retur-invoice="{{ $po->po_number }}"
                        class="flex-none min-h-[44px] px-4 rounded-md text-sm font-semibold text-red-700 bg-red-50 hover:bg-red-100">
                    Retur Barang
                </button>
            @endif
        </div>

        @if ($po->purchaseReturns->isEmpty())
            <p class="text-sm text-gray-400">Belum ada retur untuk PO ini.</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($po->purchaseReturns as $ret)
                    <li class="py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($showValues)
                                    <a href="{{ route('admin.retur-pembelian.show', $ret) }}" class="font-semibold text-indigo-600 hover:underline">{{ $ret->return_number }}</a>
                                @else
                                    <span class="font-semibold text-gray-900">{{ $ret->return_number }}</span>
                                @endif
                                <span class="ml-1.5 inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $statusBadge[$ret->status][1] }}">
                                    {{ $statusBadge[$ret->status][0] }}@if ($ret->status === 'settled' && $ret->settlement_type) &middot; {{ $settlementLabel[$ret->settlement_type] }}@endif
                                </span>
                                <div class="text-xs text-gray-400 mt-0.5">{{ $ret->user->name ?? '—' }} &middot; {{ $ret->created_at->format('d M Y, H:i') }}</div>
                                <div class="text-xs text-gray-600 mt-1">
                                    {{ $ret->items->map(fn ($i) => Number::trim($i->quantity) . ' ' . $i->unit_name . ' ' . $i->product_name)->implode(', ') }}
                                </div>
                                <div class="text-xs text-gray-500 mt-0.5">Alasan: {{ $ret->reason }}</div>
                            </div>
                            @if ($showValues)
                                <div class="flex-none font-semibold text-gray-900 whitespace-nowrap">Rp {{ number_format($ret->total_value, 0, ',', '.') }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif