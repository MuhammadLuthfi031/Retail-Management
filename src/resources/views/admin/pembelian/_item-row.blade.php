@php
    // $item selalu berupa stdClass seragam (lihat _form.blade.php) baik yang
    // berasal dari DB (mode edit) maupun hasil rekonstruksi old('items') saat
    // validasi gagal — supaya partial ini tidak perlu tahu sumbernya dari mana.
    $productData = collect($products)->firstWhere('id', (int) $item->product_id);
    $unitsForProduct = $productData['units'] ?? collect();
@endphp

<tr data-item-row class="border-t border-gray-100">
    <td class="px-2 py-1.5">
        <select data-field="product_id" name="items[{{ $index }}][product_id]" required class="w-40 rounded-md border-gray-300 text-sm">
            <option value="">-- Pilih Produk --</option>
            @foreach ($products as $p)
                <option value="{{ $p['id'] }}" @selected((int) $item->product_id === (int) $p['id'])>{{ $p['name'] }}</option>
            @endforeach
        </select>
        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
    </td>
    <td class="px-2 py-1.5">
        <select data-field="product_unit_id" name="items[{{ $index }}][product_unit_id]" required class="w-28 rounded-md border-gray-300 text-sm">
            @foreach ($unitsForProduct as $u)
                <option value="{{ $u['id'] }}" @selected((int) $item->product_unit_id === (int) $u['id'])>{{ $u['unit_name'] }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-2 py-1.5">
        <input type="number" step="0.001" min="0.001" data-field="quantity_ordered"
               name="items[{{ $index }}][quantity_ordered]" value="{{ $item->quantity_ordered }}"
               required class="w-24 rounded-md border-gray-300 text-sm">
    </td>
    <td class="px-2 py-1.5">
        <input type="number" min="0" data-field="unit_price"
               name="items[{{ $index }}][unit_price]" value="{{ $item->unit_price }}"
               required class="w-28 rounded-md border-gray-300 text-sm">
    </td>
    <td class="px-2 py-1.5 text-right text-gray-500 text-sm" data-row-subtotal>
        Rp {{ number_format($item->subtotal, 0, ',', '.') }}
    </td>
    <td class="px-2 py-1.5 text-right">
        <button type="button" data-item-remove class="text-red-500 hover:text-red-700 text-xs">Hapus</button>
    </td>
</tr>
