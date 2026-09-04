@php
    // $unit selalu berupa stdClass seragam yang sudah disiapkan di
    // _form.blade.php (baik dari DB mode edit, maupun hasil rekonstruksi
    // old('units') saat validasi gagal) — sudah dilengkapi flag:
    // - is_base_row : apakah baris ini SEDANG jadi baris paling bawah (dasar)
    // - is_locked   : apakah baris ini TIDAK BOLEH dihapus (satuan dasar yang
    //                 sudah tersimpan permanen sejak produk dibuat)
    $isBaseRow = (bool) ($unit->is_base_row ?? false);
    $isLocked = (bool) ($unit->is_locked ?? false);
    $isPurchase = (bool) $unit->is_purchase_unit;
@endphp

<tr data-unit-row class="border-t border-gray-100" data-locked="{{ $isLocked ? '1' : '0' }}">
    <td class="px-2 py-1.5">
        <input type="text" name="units[{{ $index }}][unit_name]" value="{{ $unit->unit_name }}"
               data-field="unit_name" placeholder="contoh: renceng" required class="w-full rounded-md border-gray-300 text-sm">
        <input type="hidden" name="units[{{ $index }}][id]" value="{{ $unit->id }}">
    </td>
    <td class="px-2 py-1.5">
        <input type="number" step="0.001" min="0.001" name="units[{{ $index }}][relative_qty]"
               value="{{ $unit->relative_qty }}" data-field="relative_qty"
               {{ $isBaseRow ? '' : 'required' }}
               class="w-24 rounded-md border-gray-300 text-sm {{ $isBaseRow ? 'hidden' : '' }}" placeholder="qty">
        <span class="block text-xs text-gray-400 mt-0.5" data-conversion-hint></span>
        <span class="inline-flex items-center px-2 py-1 rounded-md bg-indigo-50 text-indigo-700 text-xs font-medium {{ $isBaseRow ? '' : 'hidden' }}" data-base-label>
            Satuan Dasar
        </span>
    </td>
    <td class="px-2 py-1.5">
        <input type="number" min="0" name="units[{{ $index }}][selling_price]" value="{{ $unit->selling_price }}"
               placeholder="opsional" class="w-28 rounded-md border-gray-300 text-sm">
    </td>
    <td class="px-2 py-1.5">
        <input type="text" name="units[{{ $index }}][barcode]" value="{{ $unit->barcode }}"
               data-barcode-row-target placeholder="opsional" class="w-32 rounded-md border-gray-300 text-sm">
    </td>
    <td class="px-2 py-1.5 text-center">
        <input type="radio" name="is_purchase_unit_index_{{ $formId }}" value="{{ $index }}" @checked($isPurchase)>
    </td>
    <td class="px-2 py-1.5 text-right">
        <button type="button" data-unit-remove class="text-red-500 hover:text-red-700 text-xs {{ $isLocked ? 'hidden' : '' }}">Hapus</button>
    </td>
</tr>
