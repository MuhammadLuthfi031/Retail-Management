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
    $canEdit = $po->status === 'draft';
    $canCancel = in_array($po->status, ['draft', 'ordered'], true) && ! $po->items->contains(fn ($i) => $i->quantity_received > 0);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('admin.pembelian.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar PO</a>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">{{ $po->po_number }}</h2>
            </div>
            <span class="inline-flex px-3 py-1 rounded-full text-xs font-medium {{ $statusColor[$po->status] }}">
                {{ $statusLabel[$po->status] }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            <!-- Info & Aksi -->
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <div class="text-xs text-gray-400 uppercase">Supplier</div>
                        <div class="font-medium text-gray-900">{{ $po->supplier->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase">Dibuat Oleh</div>
                        <div class="font-medium text-gray-900">{{ $po->createdBy->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase">Tgl Pesan</div>
                        <div class="font-medium text-gray-900">{{ $po->order_date->format('d M Y') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase">Target Terima</div>
                        <div class="font-medium text-gray-900">{{ $po->expected_date?->format('d M Y') ?? '—' }}</div>
                    </div>
                </div>

                @if ($po->note)
                    <div class="text-sm text-gray-500 mb-4">
                        <span class="text-xs text-gray-400 uppercase block">Catatan</span>
                        {{ $po->note }}
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 pt-4 border-t border-gray-100">
                    @if ($canEdit)
                        <button type="button" data-modal-open="edit-po" class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-gray-100 text-gray-700 hover:bg-gray-200">
                            Edit
                        </button>

                        <form method="POST" action="{{ route('admin.pembelian.mark-ordered', $po) }}" onsubmit="return confirm('Tandai PO ini sudah dipesan ke supplier? PO akan muncul di daftar konfirmasi penerimaan Gudang.');">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-blue-600 text-white hover:bg-blue-700">
                                Tandai Dipesan
                            </button>
                        </form>

                        <button type="button" data-modal-open="delete-po" class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-white text-red-600 border border-red-200 hover:bg-red-50">
                            Hapus
                        </button>
                    @endif

                    @if ($canCancel)
                        <form method="POST" action="{{ route('admin.pembelian.cancel', $po) }}" onsubmit="return confirm('Batalkan PO ini?');">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-white text-red-600 border border-red-200 hover:bg-red-50">
                                Batalkan PO
                            </button>
                        </form>
                    @endif

                    <div class="ml-auto flex items-center gap-2">
                        <div class="text-right">
                            <div class="text-xs text-gray-400 uppercase">Status Bayar</div>
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $po->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : ($po->payment_status === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ $paymentLabel[$po->payment_status] }}
                            </span>
                        </div>
                        @if ($po->payment_status !== 'paid')
                            <button type="button" data-modal-open="ubah-status-bayar" class="px-3 py-1.5 rounded-md text-xs font-semibold uppercase tracking-wide bg-gray-100 text-gray-700 hover:bg-gray-200">
                                Ubah
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            @if ($po->payments->isNotEmpty())
                <!-- Riwayat Bukti Pembayaran -->
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Riwayat Bukti Pembayaran</h3>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($po->payments as $payment)
                            <li class="py-2.5 flex items-center justify-between gap-3">
                                <div>
                                    <span class="font-medium text-gray-800">{{ $paymentLabel[$payment->from_status] }} &rarr; {{ $paymentLabel[$payment->to_status] }}</span>
                                    <div class="text-xs text-gray-400">
                                        {{ $payment->uploadedBy->name ?? '—' }} &middot; {{ $payment->created_at->format('d M Y H:i') }}
                                    </div>
                                </div>
                                <a href="{{ Storage::url($payment->proof_path) }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline text-xs font-medium shrink-0">
                                    Lihat Bukti
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Items -->
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Satuan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Dipesan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Diterima</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Harga</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($po->items as $item)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $item->product->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $item->productUnit->unit_name }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">{{ \App\Support\Number::trim((float) $item->quantity_ordered) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <span @class([
                                        'font-medium',
                                        'text-emerald-600' => $item->isFullyReceived(),
                                        'text-amber-600' => $item->quantity_received > 0 && ! $item->isFullyReceived(),
                                        'text-gray-400' => $item->quantity_received == 0,
                                    ])>
                                        {{ \App\Support\Number::trim((float) $item->quantity_received) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-gray-900">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50">
                            <td colspan="5" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-900">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    @if ($canEdit)
        <!-- Modal Edit -->
        <x-modal.modal name="edit-po" maxWidth="2xl">
            <form method="POST" action="{{ route('admin.pembelian.update', $po) }}" class="p-6">
                @csrf
                @method('PUT')
                <h3 class="text-lg font-medium text-gray-900 mb-4">Edit PO {{ $po->po_number }}</h3>

                @include('admin.pembelian._form', ['po' => $po, 'formId' => 'edit-po', 'suppliers' => $suppliers, 'products' => $products])

                <div class="flex justify-end gap-2 mt-6">
                    <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                    <x-primary-button>Simpan Perubahan</x-primary-button>
                </div>
            </form>
        </x-modal.modal>

        <!-- Modal Delete -->
        <x-modal.modal name="delete-po">
            <form method="POST" action="{{ route('admin.pembelian.destroy', $po) }}" class="p-6">
                @csrf
                @method('DELETE')
                <h3 class="text-lg font-medium text-gray-900 mb-2">Hapus PO?</h3>
                <p class="text-sm text-gray-500 mb-4">
                    PO "<strong>{{ $po->po_number }}</strong>" beserta semua itemnya akan dihapus permanen.
                </p>
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                    <x-danger-button>Ya, Hapus</x-danger-button>
                </div>
            </form>
        </x-modal.modal>
    @endif

    @if ($po->payment_status !== 'paid')
        <!-- Modal Ubah Status Bayar -->
        <x-modal.modal name="ubah-status-bayar">
            <form method="POST" action="{{ route('admin.pembelian.payment-status', $po) }}" enctype="multipart/form-data" class="p-6">
                @csrf
                @method('PUT')
                <h3 class="text-lg font-medium text-gray-900 mb-1">Ubah Status Pembayaran</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Status saat ini: <strong>{{ $paymentLabel[$po->payment_status] }}</strong>.
                    Status pembayaran tidak bisa dikembalikan mundur setelah disimpan.
                </p>

                <input type="hidden" name="form_id" value="ubah-status-bayar">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status Baru</label>
                    <select name="payment_status" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="" disabled selected>Pilih status baru...</option>
                        @foreach ($paymentLabel as $key => $label)
                            @continue(\App\Models\PurchaseOrder::PAYMENT_STATUS_RANK[$key] <= \App\Models\PurchaseOrder::PAYMENT_STATUS_RANK[$po->payment_status])
                            <option value="{{ $key }}" @selected(old('payment_status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_status')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bukti Pembayaran</label>
                    <input type="file" name="proof" required accept="image/jpeg,image/png,image/webp"
                           class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="text-xs text-gray-400 mt-1">Format JPG/PNG/WEBP, maksimal 2MB. Wajib diisi.</p>
                    @error('proof')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                    <x-primary-button>Simpan</x-primary-button>
                </div>
            </form>
        </x-modal.modal>
    @endif

    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>