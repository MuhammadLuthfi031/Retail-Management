<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('gudang.pembelian.index') }}" class="text-xs text-indigo-600 hover:underline">&larr; Kembali ke daftar penerimaan</a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1">Terima Barang — {{ $po->po_number }}</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            @error('received')
                <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
            @enderror

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <div class="text-xs text-gray-400 uppercase">Supplier</div>
                    <div class="font-medium text-gray-900">{{ $po->supplier->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 uppercase">Target Terima</div>
                    <div class="font-medium text-gray-900">{{ $po->expected_date?->format('d M Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 uppercase">Catatan PO</div>
                    <div class="font-medium text-gray-900">{{ $po->note ?: '—' }}</div>
                </div>
            </div>

            @unless ($canReceive)
                <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                    PO ini sudah <strong>diterima lengkap</strong> — halaman ini sekarang cuma menampilkan riwayat, tidak bisa menerima barang lagi.
                </div>
            @endunless

            <form method="POST" action="{{ route('gudang.pembelian.store', $po) }}" enctype="multipart/form-data">
                @csrf

                <!-- Tabel — desktop/tablet (≥768px), tidak berubah -->
                <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Produk</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Satuan</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Dipesan</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Sudah Diterima</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Sisa</th>
                                @if ($canReceive)
                                    <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Terima Sekarang</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($po->items as $item)
                                @php $remaining = $item->remainingQuantity(); @endphp
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $item->product->name }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $item->productUnit->unit_name }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ \App\Support\Number::trim((float) $item->quantity_ordered) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ \App\Support\Number::trim((float) $item->quantity_received) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-500">{{ \App\Support\Number::trim($remaining) }}</td>
                                    @if ($canReceive)
                                        <td class="px-4 py-3 text-right">
                                            @if ($remaining > 0)
                                                <input type="number" step="0.001" min="0" max="{{ $remaining }}"
                                                       name="received[{{ $item->id }}]" value="{{ old('received.' . $item->id, '') }}"
                                                       placeholder="0" class="w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-indigo-500 focus:ring-indigo-500">
                                            @else
                                                <span class="text-xs text-emerald-600 font-medium">Lengkap</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Kartu — mobile (<768px): input qty SAMA persis (name, id, max, old())
                     supaya submit form tetap jalan identik dengan versi tabel di atas. -->
                <div class="md:hidden space-y-2.5">
                    @foreach ($po->items as $item)
                        @php $remaining = $item->remainingQuantity(); @endphp
                        <div class="bg-white border border-gray-200 rounded-lg p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-medium text-gray-900 truncate">{{ $item->product->name }}</div>
                                <span class="flex-none text-[10.5px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-medium">{{ $item->productUnit->unit_name }}</span>
                            </div>
                            <div class="mt-2.5 pt-2.5 border-t border-dashed border-gray-200 grid grid-cols-3 gap-2 text-xs text-center">
                                <div>
                                    <div class="text-gray-400">Dipesan</div>
                                    <div class="text-gray-700 font-medium mt-0.5">{{ \App\Support\Number::trim((float) $item->quantity_ordered) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-400">Diterima</div>
                                    <div class="text-gray-700 font-medium mt-0.5">{{ \App\Support\Number::trim((float) $item->quantity_received) }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-400">Sisa</div>
                                    <div class="text-gray-700 font-medium mt-0.5">{{ \App\Support\Number::trim($remaining) }}</div>
                                </div>
                            </div>
                            @if ($canReceive)
                                <div class="mt-2.5 pt-2.5 border-t border-dashed border-gray-200">
                                    @if ($remaining > 0)
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Terima Sekarang</label>
                                        <input type="number" step="0.001" min="0" max="{{ $remaining }}"
                                               name="received[{{ $item->id }}]" value="{{ old('received.' . $item->id, '') }}"
                                               placeholder="0" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @else
                                        <span class="text-xs text-emerald-600 font-medium">✓ Lengkap</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($canReceive)
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bukti Penerimaan Barang</label>
                        <p class="text-xs text-gray-400 mb-2">Foto fisik barang datang / nota pengiriman dari supplier. Wajib diisi sebelum konfirmasi.</p>
                        <input type="file" name="proof" required accept="image/jpeg,image/png,image/webp"
                               class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        <p class="text-xs text-gray-400 mt-1">Format JPG/PNG/WEBP, maksimal 2MB.</p>
                        @error('proof')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end mt-4">
                        <x-primary-button>Konfirmasi Penerimaan</x-primary-button>
                    </div>
                @endif
            </form>

            @if ($po->receipts->isNotEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Riwayat Bukti Penerimaan</h3>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($po->receipts as $receipt)
                            <li class="py-2.5 flex items-center justify-between gap-3">
                                <div class="text-xs text-gray-400">
                                    {{ $receipt->receivedBy->name ?? '—' }} &middot; {{ $receipt->created_at->format('d M Y H:i') }}
                                </div>
                                <a href="{{ Storage::url($receipt->proof_path) }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline text-xs font-medium shrink-0">
                                    Lihat Bukti
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>