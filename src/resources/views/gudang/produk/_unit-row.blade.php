@php
    // $unit bisa berupa model ProductUnit (mode edit, belum ada error) ATAU
    // stdClass hasil rekonstruksi dari old('units') (form gagal validasi,
    // lihat _form.blade.php). Keduanya punya properti yang sama jadi Blade-nya
    // tetap sama persis di kedua kasus.
    $isBase = (bool) $unit->is_base_unit;
    $isPurchase = (bool) $unit->is_purchase_unit;

    // "1.000" dari decimal cast DB dirapikan jadi "1" biar enak dibaca di
    // input; tapi kalau nilainya dari old() (string mentah ketikan user),
    // biarkan apa adanya supaya tidak mengubah yang tadi diketik.
    $conversionValue = $unit->conversion_to_base;
    if (is_numeric($conversionValue) && str_contains((string) $conversionValue, '.')) {
        $conversionValue = rtrim(rtrim((string) $conversionValue, '0'), '.');
    }
@endphp

<tr data-unit-row class="border-t border-gray-100">
    <td class="px-2 py-1.5">
        <input type="text" name="units[{{ $index }}][unit_name]" value="{{ $unit->unit_name }}"
               placeholder="contoh: renceng" required class="w-full rounded-md border-gray-300 text-sm">
        <input type="hidden" name="units[{{ $index }}][id]" value="{{ $unit->id }}">
    </td>
    <td class="px-2 py-1.5">
        <input type="number" step="0.001" min="0.001" name="units[{{ $index }}][conversion_to_base]"
               value="{{ $conversionValue }}" required
               class="w-24 rounded-md border-gray-300 text-sm {{ $isBase ? 'bg-gray-50' : '' }}"
               {{ $isBase ? 'readonly' : '' }}>
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
        <input type="radio" name="is_base_unit_index_{{ $formId }}" value="{{ $index }}" @checked($isBase)>
    </td>
    <td class="px-2 py-1.5 text-center">
        <input type="radio" name="is_purchase_unit_index_{{ $formId }}" value="{{ $index }}" @checked($isPurchase)>
    </td>
    <td class="px-2 py-1.5 text-right">
        <button type="button" data-unit-remove class="text-red-500 hover:text-red-700 text-xs">Hapus</button>
    </td>
</tr>
