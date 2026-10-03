@php
    use App\Support\Number;

    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];

    // Data untuk estimasi live di browser. Rumusnya SAMA dengan server
    // (SalesReturnService::refundFor), tapi hanya perkiraan — angka resmi
    // selalu dihitung ulang server saat disimpan.
    $calc = $states->map(fn ($s) => [
        'id' => $s['detail']->id,
        'sold' => $s['sold_milli'],
        'remaining' => $s['remaining_milli'],
        'subtotal' => (int) $s['detail']->subtotal,
        'remainingRefund' => $s['remaining_refund'],
    ])->values();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.retur-penjualan.index', ['cari' => $transaction->invoice_number]) }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Retur — {{ $transaction->invoice_number }}</h2>
        </div>
    </x-slot>

    <div class="py-8" x-data="returForm(@js($calc), @js(old('items', [])))">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-alert />

            <div class="bg-white shadow-sm sm:rounded-lg p-4 text-sm grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><div class="text-xs text-gray-400">Tanggal</div>{{ $transaction->created_at->format('d M Y, H:i') }}</div>
                <div><div class="text-xs text-gray-400">Kasir</div>{{ $transaction->user->name }}</div>
                <div><div class="text-xs text-gray-400">Pembayaran</div>{{ $paymentLabel[$transaction->payment_method] ?? $transaction->payment_method }}</div>
                <div><div class="text-xs text-gray-400">Total</div>Rp {{ number_format($transaction->grand_total, 0, ',', '.') }}</div>
            </div>

            @if (! $canReturn)
                <div class="rounded-lg bg-gray-100 border border-gray-200 px-4 py-6 text-center text-sm text-gray-600">
                    Semua barang pada transaksi ini sudah diretur penuh. Tidak ada sisa yang bisa diretur.
                </div>
            @else
                <form method="POST" action="{{ route('admin.retur-penjualan.store', $transaction) }}"
                      @submit="if (submitting) { $event.preventDefault(); return; } if (!confirm('Retur tidak bisa dibatalkan setelah disimpan. Lanjutkan?')) { $event.preventDefault(); return; } submitting = true;"
                      class="space-y-4">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                    <div class="space-y-3">
                        @foreach ($states as $id => $s)
                            @php
                                $d = $s['detail'];
                                $fractional = $d->product->allow_fractional_sale;
                                $sisa = $s['remaining_milli'] / 1000;
                            @endphp
                            <div class="bg-white border border-gray-200 rounded-xl p-4 {{ $s['remaining_milli'] === 0 ? 'opacity-60' : '' }}">
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
                                                   name="items[{{ $id }}][qty]" x-model="qty[{{ $id }}]"
                                                   value="{{ old("items.$id.qty") }}" placeholder="0"
                                                   class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 mb-1">Kondisi barang</label>
                                            <select name="items[{{ $id }}][condition]"
                                                    class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="resellable" @selected(old("items.$id.condition", 'resellable') === 'resellable')>Layak jual (masuk stok)</option>
                                                <option value="damaged" @selected(old("items.$id.condition") === 'damaged')>Rusak (tidak masuk stok)</option>
                                            </select>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Uang dikembalikan lewat</label>
                                <select name="refund_method" required
                                        class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach ($paymentLabel as $val => $label)
                                        <option value="{{ $val }}" @selected(old('refund_method', $transaction->payment_method) === $val)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Alasan retur</label>
                                <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" required
                                       placeholder="mis. kemasan rusak, salah beli"
                                       class="w-full min-h-[44px] rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>

                    <div class="sticky bottom-16 md:bottom-0 bg-white border border-gray-200 rounded-xl p-4 flex items-center justify-between gap-3 shadow-sm">
                        <div>
                            <div class="text-xs text-gray-400">Perkiraan uang dikembalikan</div>
                            <div class="text-lg font-bold text-gray-900" x-text="rupiah(total())">Rp 0</div>
                        </div>
                        <button type="submit" :disabled="submitting || total() === 0 && !anyQty()"
                                class="min-h-[44px] px-6 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-md text-sm font-semibold">
                            <span x-show="!submitting">Proses Retur</span>
                            <span x-show="submitting" x-cloak>Memproses…</span>
                        </button>
                    </div>
                </form>

                <script>
                    function returForm(lines, old) {
                        const qty = {};
                        lines.forEach(l => { qty[l.id] = old && old[l.id] && old[l.id].qty ? old[l.id].qty : ''; });
                        return {
                            qty, submitting: false,
                            anyQty() { return Object.values(this.qty).some(v => parseFloat(v) > 0); },
                            total() {
                                return lines.reduce((sum, l) => {
                                    const q = Math.round((parseFloat(this.qty[l.id]) || 0) * 1000);
                                    if (q <= 0 || q > l.remaining) return sum;
                                    const r = q === l.remaining ? l.remainingRefund : Math.min(Math.round(l.subtotal * q / l.sold), l.remainingRefund);
                                    return sum + r;
                                }, 0);
                            },
                            rupiah(n) { return 'Rp ' + n.toLocaleString('id-ID'); },
                        };
                    }
                </script>
            @endif
        </div>
    </div>
</x-app-layout>