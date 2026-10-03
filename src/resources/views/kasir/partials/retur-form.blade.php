@php
    use App\Support\Number;

    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
@endphp

{{--
    Isi modal retur. Dirender server (SalesReturnController via Kasir\ReturController::form)
    lalu disisipkan JS ke dalam #modal-retur. Semua interaksi (hitung estimasi, kirim,
    tampil error) ada di resources/js/retur-modal.js lewat atribut data-retur-*.
--}}
<form data-retur-form data-allow-resubmit
      action="{{ route('kasir.riwayat.retur.store', $transaction) }}"
      class="space-y-4" autocomplete="off">
    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

    <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm grid grid-cols-2 gap-x-4 gap-y-2">
        <div><div class="text-[11px] text-gray-400">Invoice</div><span class="font-semibold text-gray-900">{{ $transaction->invoice_number }}</span></div>
        <div><div class="text-[11px] text-gray-400">Tanggal</div>{{ $transaction->created_at->format('d M Y, H:i') }}</div>
        <div><div class="text-[11px] text-gray-400">Kasir</div>{{ $transaction->user->name }}</div>
        <div><div class="text-[11px] text-gray-400">Total / bayar via</div>Rp {{ number_format($transaction->grand_total, 0, ',', '.') }} &middot; {{ $paymentLabel[$transaction->payment_method] ?? $transaction->payment_method }}</div>
    </div>

    @if (! $canReturn)
        <div class="rounded-lg bg-gray-100 border border-gray-200 px-4 py-6 text-center text-sm text-gray-600">
            Semua barang pada transaksi ini sudah diretur penuh. Tidak ada sisa yang bisa diretur.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($states as $id => $s)
                @php
                    $d = $s['detail'];
                    $fractional = $d->product->allow_fractional_sale;
                    $sisa = $s['remaining_milli'] / 1000;
                @endphp
                <div data-retur-line
                     data-sold="{{ $s['sold_milli'] }}"
                     data-remaining="{{ $s['remaining_milli'] }}"
                     data-subtotal="{{ (int) $d->subtotal }}"
                     data-remaining-refund="{{ $s['remaining_refund'] }}"
                     class="border border-gray-200 rounded-xl p-3.5 {{ $s['remaining_milli'] === 0 ? 'opacity-60' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-900 text-sm">{{ $d->product_name }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                Terjual {{ Number::trim($d->quantity) }} {{ $d->unit_name }}
                                @ Rp {{ number_format($d->price, 0, ',', '.') }}
                                @if ($d->discount_amount > 0) &middot; diskon Rp {{ number_format($d->discount_amount, 0, ',', '.') }} @endif
                                &middot; subtotal Rp {{ number_format($d->subtotal, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="text-right text-xs flex-none">
                            <div class="text-gray-400">Sisa bisa diretur</div>
                            <div class="font-semibold text-gray-900">{{ Number::trim($sisa) }} {{ $d->unit_name }}</div>
                        </div>
                    </div>

                    @if ($s['remaining_milli'] === 0)
                        <p class="mt-2 text-xs text-gray-500">Sudah diretur penuh.</p>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Qty retur ({{ $d->unit_name }})</label>
                                <input type="number" inputmode="decimal" min="0" max="{{ Number::trim($sisa) }}"
                                       step="{{ $fractional ? '0.001' : '1' }}"
                                       name="items[{{ $id }}][qty]" data-retur-qty placeholder="0"
                                       class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Kondisi barang</label>
                                <select name="items[{{ $id }}][condition]"
                                        class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="resellable">Layak jual (masuk stok)</option>
                                    <option value="damaged">Rusak (tidak masuk stok)</option>
                                </select>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Uang dikembalikan lewat</label>
                <select name="refund_method" required
                        class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach ($paymentLabel as $val => $label)
                        <option value="{{ $val }}" @selected($transaction->payment_method === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Alasan retur</label>
                <input type="text" name="reason" maxlength="255" required placeholder="mis. kemasan rusak, salah beli"
                       class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
        </div>

        <div class="sticky bottom-0 -mx-5 -mb-5 px-5 py-3 bg-white border-t border-gray-100 flex items-center justify-between gap-3">
            <div>
                <div class="text-xs text-gray-400">Perkiraan uang dikembalikan</div>
                <div class="text-lg font-bold text-gray-900" data-retur-total>Rp 0</div>
            </div>
            <button type="submit" data-retur-submit disabled
                    class="min-h-[44px] px-6 bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-md text-sm font-semibold">
                Proses Retur
            </button>
        </div>
    @endif
</form>