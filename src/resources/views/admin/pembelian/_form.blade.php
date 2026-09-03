@php
    $isEdit = (bool) $po;
    $reopening = old('form_id') === $formId;

    if ($reopening) {
        $itemRows = collect(old('items', []))->map(fn ($row, $i) => (object) [
            'id' => $row['id'] ?? null,
            'product_id' => $row['product_id'] ?? '',
            'product_unit_id' => $row['product_unit_id'] ?? '',
            'quantity_ordered' => $row['quantity_ordered'] ?? '',
            'unit_price' => $row['unit_price'] ?? '',
            'subtotal' => ((float) ($row['quantity_ordered'] ?? 0)) * ((float) ($row['unit_price'] ?? 0)),
        ])->values();
    } elseif ($isEdit) {
        $itemRows = $po->items->map(fn ($item) => (object) [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_unit_id' => $item->product_unit_id,
            'quantity_ordered' => rtrim(rtrim(number_format((float) $item->quantity_ordered, 3, '.', ''), '0'), '.'),
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
        ]);
    } else {
        $itemRows = collect();
    }
@endphp

<input type="hidden" name="form_id" value="{{ $formId }}">

@error('items')
    <div class="mb-3 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">{{ $message }}</div>
@enderror

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
    <div>
        <x-input-label value="Supplier" />
        <select name="supplier_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">-- Pilih Supplier --</option>
            @foreach ($suppliers as $s)
                <option value="{{ $s->id }}" @selected(old('supplier_id', $po->supplier_id ?? null) == $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label value="Tanggal Pesan" />
        <x-text-input type="date" name="order_date" value="{{ old('order_date', $po?->order_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="mt-1 block w-full" required />
    </div>
    <div>
        <x-input-label value="Target Tanggal Terima (opsional)" />
        <x-text-input type="date" name="expected_date" value="{{ old('expected_date', $po?->expected_date?->format('Y-m-d')) }}" class="mt-1 block w-full" />
    </div>
</div>

<div data-item-section class="mb-4">
    <div class="flex items-center justify-between mb-2">
        <x-input-label value="Item Pembelian" />
        <button type="button" data-item-add="{{ $formId }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
            + Tambah Item
        </button>
    </div>

    <div class="border border-gray-200 rounded-lg overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-2 py-2 text-left">Produk</th>
                    <th class="px-2 py-2 text-left">Satuan Beli</th>
                    <th class="px-2 py-2 text-left">Qty</th>
                    <th class="px-2 py-2 text-left">Harga/Satuan</th>
                    <th class="px-2 py-2 text-right">Subtotal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody data-item-rows="{{ $formId }}" data-counter="{{ $itemRows->count() }}">
                @foreach ($itemRows as $index => $item)
                    @include('admin.pembelian._item-row', ['item' => $item, 'index' => $index, 'products' => $products])
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-gray-200 bg-gray-50">
                    <td colspan="4" class="px-2 py-2 text-right text-xs font-medium text-gray-500">TOTAL</td>
                    <td class="px-2 py-2 text-right font-semibold text-gray-900" data-grand-total="{{ $formId }}">
                        Rp {{ number_format($itemRows->sum('subtotal'), 0, ',', '.') }}
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @if ($itemRows->isEmpty())
            <p class="px-3 py-3 text-xs text-gray-400" data-item-empty-hint>
                Belum ada item. Klik "+ Tambah Item" untuk menambahkan produk yang mau dipesan.
            </p>
        @endif
    </div>
</div>

<div>
    <x-input-label value="Catatan (opsional)" />
    <textarea name="note" rows="2"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('note', $po->note ?? '') }}</textarea>
</div>

<!-- Template baris item baru -->
<template data-item-row-template="{{ $formId }}">
    <tr data-item-row class="border-t border-gray-100">
        <td class="px-2 py-1.5">
            <select data-field="product_id" required class="w-40 rounded-md border-gray-300 text-sm">
                <option value="">-- Pilih Produk --</option>
                @foreach ($products as $p)
                    <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                @endforeach
            </select>
            <input type="hidden" data-field="id" value="">
        </td>
        <td class="px-2 py-1.5">
            <select data-field="product_unit_id" required class="w-28 rounded-md border-gray-300 text-sm">
                <option value="">-- pilih produk dulu --</option>
            </select>
        </td>
        <td class="px-2 py-1.5">
            <input type="number" step="0.001" min="0.001" data-field="quantity_ordered" required class="w-24 rounded-md border-gray-300 text-sm">
        </td>
        <td class="px-2 py-1.5">
            <input type="number" min="0" data-field="unit_price" required class="w-28 rounded-md border-gray-300 text-sm">
        </td>
        <td class="px-2 py-1.5 text-right text-gray-500 text-sm" data-row-subtotal>Rp 0</td>
        <td class="px-2 py-1.5 text-right">
            <button type="button" data-item-remove class="text-red-500 hover:text-red-700 text-xs">Hapus</button>
        </td>
    </tr>
</template>

<!-- Data produk + satuan untuk JS (dropdown satuan dinamis mengikuti produk yang dipilih) -->
<script type="application/json" data-products-data="{{ $formId }}">{!! json_encode($products) !!}</script>