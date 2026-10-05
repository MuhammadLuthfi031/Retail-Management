@php
    use App\Support\Number;
@endphp

{{--
    Isi modal retur ke supplier. Dirender server (Gudang\ReturSupplierController::form) lalu
    disisipkan JS ke #modal-retur; interaksinya ada di resources/js/retur-modal.js lewat atribut data-retur-*.

    PENTING: $showValues = false (Gudang) -> TIDAK ADA harga/nilai di HTML ini, termasuk atribut data-*.
--}}
<form data-retur-form data-allow-resubmit
      action="{{ route('gudang.pembelian.retur.store', $po) }}"
      class="space-y-4" autocomplete="off">
    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

    <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm grid grid-cols-2 gap-x-4 gap-y-2">
        <div><div class="text-[11px] text-gray-400">No. PO</div><span class="font-semibold text-gray-900">{{ $po->po_number }}</span></div>
        <div><div class="text-[11px] text-gray-400">Supplier</div>{{ $po->supplier->name ?? '—' }}</div>
    </div>

    @if (! $canReturn)
        <div class="rounded-lg bg-gray-100 border border-gray-200 px-4 py-6 text-center text-sm text-gray-600">
            Semua barang yang diterima pada PO ini sudah diretur penuh. Tidak ada sisa yang bisa diretur.
        </div>
    @else
        <p class="text-xs text-gray-500">
            Stok langsung berkurang saat retur disimpan. Satuan retur boleh berbeda dari satuan beli (mis. beli dus, retur sachet).
        </p>

        <div class="space-y-3">
            @foreach ($states as $id => $s)
                @php
                    $item = $s['item'];
                    $product = $item->product;
                    $buyUnit = $item->productUnit;
                    $baseName = $s['base_unit_name'];
                    $remainingBase = $s['remaining_base_milli'] / 1000;
                    $remainingBuy = $remainingBase / max((float) $buyUnit->conversion_to_base, 0.001);
                    $returnedBase = $s['returned_base_milli'] / 1000;
                @endphp
                <div data-retur-line data-mode="supplier"
                     data-remaining-base="{{ $s['remaining_base_milli'] }}"
                     @if ($showValues)
                         data-received-base="{{ $s['received_base_milli'] }}"
                         data-total-value="{{ $s['total_value'] }}"
                         data-remaining-value="{{ $s['remaining_value'] }}"
                     @endif
                     class="border border-gray-200 rounded-xl p-3.5 {{ $s['remaining_base_milli'] === 0 ? 'opacity-60' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-900 text-sm">{{ $product->name }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                Diterima {{ Number::trim($item->quantity_received) }} {{ $buyUnit->unit_name }}
                                @if ($returnedBase > 0) &middot; sudah diretur {{ Number::trim($returnedBase) }} {{ $baseName }} @endif
                                @if ($showValues) &middot; harga beli Rp {{ number_format($item->unit_price, 0, ',', '.') }}/{{ $buyUnit->unit_name }} @endif
                            </div>
                        </div>
                        <div class="text-right text-xs flex-none">
                            <div class="text-gray-400">Sisa bisa diretur</div>
                            <div class="font-semibold text-gray-900">
                                {{ Number::trim(round($remainingBuy, 3)) }} {{ $buyUnit->unit_name }}
                                @if ($buyUnit->id !== ($product->units->firstWhere('is_base_unit', true)?->id))
                                    <span class="block font-normal text-gray-500">= {{ Number::trim($remainingBase) }} {{ $baseName }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($s['remaining_base_milli'] === 0)
                        <p class="mt-2 text-xs text-gray-500">Sudah diretur penuh.</p>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Qty retur</label>
                                <input type="number" inputmode="decimal" min="0" step="any"
                                       name="items[{{ $id }}][qty]" data-retur-qty placeholder="0"
                                       class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Satuan retur</label>
                                <select name="items[{{ $id }}][unit_id]" data-retur-unit
                                        class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach ($product->units as $u)
                                        <option value="{{ $u->id }}"
                                                data-conv="{{ (int) round($u->conversion_to_base * 1000) }}"
                                                data-name="{{ $u->unit_name }}"
                                                @selected($u->id === $buyUnit->id)>
                                            {{ $u->unit_name }}@unless ($u->is_base_unit) (= {{ Number::trim($u->conversion_to_base) }} {{ $baseName }})@endunless
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <p class="mt-1.5 text-[11px] text-gray-400" data-retur-hint></p>
                    @endif
                </div>
            @endforeach
        </div>

        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Alasan retur</label>
            <input type="text" name="reason" maxlength="255" required placeholder="mis. kemasan rusak, kadaluarsa, salah kirim"
                   class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        <div class="sticky bottom-0 -mx-5 -mb-5 px-5 py-3 bg-white border-t border-gray-100 flex items-center justify-between gap-3">
            @if ($showValues)
                <div>
                    <div class="text-xs text-gray-400">Perkiraan nilai retur</div>
                    <div class="text-lg font-bold text-gray-900" data-retur-total>Rp 0</div>
                </div>
            @else
                <div class="text-xs text-gray-400">Stok dikurangi sesuai qty retur.</div>
            @endif
            <button type="submit" data-retur-submit disabled
                    class="min-h-[44px] px-6 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-md text-sm font-semibold">
                Proses Retur
            </button>
        </div>
    @endif
</form>