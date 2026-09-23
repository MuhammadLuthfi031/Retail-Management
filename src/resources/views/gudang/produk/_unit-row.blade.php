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

{{-- Kartu per satuan (dulu <tr> di dalam <table>, diganti kartu supaya tidak
     perlu digeser horizontal di layar kecil — lihat prototype redesign
     mobile). unit-rows.js tidak perlu diubah sama sekali: semua fungsinya
     (hapus baris, hitung ulang pratinjau konversi, kunci baris dasar) jalan
     lewat atribut data-* generik (data-unit-row, data-field, dst), bukan
     lewat struktur tabel. --}}
<div data-unit-row data-locked="{{ $isLocked ? '1' : '0' }}"
     class="rounded-lg border p-3 {{ $isBaseRow ? 'bg-indigo-50 border-indigo-200' : 'bg-white border-gray-200' }}">
    <div class="flex items-start justify-between gap-2 mb-2">
        <input type="text" name="units[{{ $index }}][unit_name]" value="{{ $unit->unit_name }}"
               data-field="unit_name" placeholder="contoh: renceng" required
               class="min-w-0 flex-1 rounded-md border-gray-300 text-sm font-semibold focus:border-indigo-500 focus:ring-indigo-500">
        <input type="hidden" name="units[{{ $index }}][id]" value="{{ $unit->id }}">
        <button type="button" data-unit-remove
                class="flex-none w-7 h-7 rounded-full bg-red-50 text-red-600 text-sm font-bold leading-none hover:bg-red-100 {{ $isLocked ? 'hidden' : '' }}">
            &times;
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2">
        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-700 text-[11px] font-semibold {{ $isBaseRow ? '' : 'hidden' }}" data-base-label>
            Satuan Dasar
        </span>
        <label class="inline-flex items-center gap-1.5 text-[11px] font-medium text-gray-500">
            <input type="radio" name="is_purchase_unit_index_{{ $formId }}" value="{{ $index }}" @checked($isPurchase)
                   data-field="is_purchase_unit" class="text-indigo-600 focus:ring-indigo-500">
            Beli Default
        </label>
    </div>

    <div class="grid grid-cols-2 gap-2 mb-2">
        <div>
            <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Isi (&rarr; bawahnya)</label>
            <input type="number" step="0.001" min="0.001" name="units[{{ $index }}][relative_qty]"
                   value="{{ $unit->relative_qty }}" data-field="relative_qty"
                   {{ $isBaseRow ? '' : 'required' }}
                   class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 {{ $isBaseRow ? 'hidden' : '' }}" placeholder="qty">
            <span class="block text-[11px] text-gray-400 mt-0.5" data-conversion-hint></span>
        </div>
        <div>
            <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Harga Jual</label>
            <input type="number" min="0" name="units[{{ $index }}][selling_price]" value="{{ $unit->selling_price }}"
                   placeholder="opsional" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
    </div>

    <div>
        <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Barcode</label>
        <input type="text" name="units[{{ $index }}][barcode]" value="{{ $unit->barcode }}"
               data-barcode-row-target placeholder="opsional" class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
</div>