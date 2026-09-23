@php
    $isEdit = (bool) $product;
    $trackingMode = old('tracking_mode', $product->tracking_mode ?? 'unit');
    $reopening = old('form_id') === $formId;

    // Satuan yang SAAT INI jadi dasar di database (null kalau produk baru).
    // Dipakai untuk menandai baris mana yang terkunci (tidak boleh dihapus),
    // dicocokkan lewat ID satuan — bukan lewat posisi — supaya tetap akurat
    // walau urutan baris di form sempat berubah gara-gara validasi gagal.
    $currentBaseUnitId = $isEdit ? optional($product->units->firstWhere('is_base_unit', true))->id : null;

    if ($reopening) {
        // Form ini yang tadi disubmit lalu gagal validasi — rekonstruksi ulang
        // dari old('units') APA ADANYA (relative_qty sudah dalam bentuk relatif
        // persis seperti yang diketik user, tidak perlu dikonversi lagi).
        $purchaseIndexOld = old("is_purchase_unit_index_{$formId}");
        $rawOldUnits = collect(old('units', []))->values();
        $lastOldIndex = $rawOldUnits->count() - 1;

        $unitRows = $rawOldUnits->map(function ($row, $i) use ($purchaseIndexOld, $lastOldIndex, $currentBaseUnitId) {
            return (object) [
                'id' => $row['id'] ?? null,
                'unit_name' => $row['unit_name'] ?? '',
                'relative_qty' => $row['relative_qty'] ?? '',
                'selling_price' => $row['selling_price'] ?? '',
                'barcode' => $row['barcode'] ?? '',
                'is_purchase_unit' => (string) $purchaseIndexOld === (string) $i,
                'is_base_row' => $i === $lastOldIndex,
                'is_locked' => $currentBaseUnitId !== null && (string) ($row['id'] ?? null) === (string) $currentBaseUnitId,
            ];
        })->values();
    } elseif ($isEdit) {
        // Mode edit tanpa error — data asli dari DB (conversion_to_base ABSOLUT)
        // diubah dulu jadi nilai RELATIF (terhadap baris tepat di bawahnya)
        // supaya yang ditampilkan di form sesuai cara input yang baru.
        $dbUnits = $product->units->values(); // sudah terurut sort_order dari controller
        $total = $dbUnits->count();

        $unitRows = $dbUnits->map(function ($unit, $i) use ($dbUnits, $total, $currentBaseUnitId) {
            $isLastPos = $i === $total - 1;
            $nextConversion = $isLastPos ? 1.0 : (float) $dbUnits[$i + 1]->conversion_to_base;
            $relative = $isLastPos || $nextConversion <= 0
                ? ''
                : \App\Support\Number::trim(((float) $unit->conversion_to_base) / $nextConversion);

            return (object) [
                'id' => $unit->id,
                'unit_name' => $unit->unit_name,
                'relative_qty' => $relative,
                'selling_price' => $unit->selling_price,
                'barcode' => $unit->barcode,
                'is_purchase_unit' => (bool) $unit->is_purchase_unit,
                'is_base_row' => $isLastPos,
                'is_locked' => $unit->id === $currentBaseUnitId,
            ];
        });
    } else {
        $unitRows = collect();
    }
@endphp

<input type="hidden" name="form_id" value="{{ $formId }}">

@error('units')
    <div class="mb-3 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
        {{ $message }}
    </div>
@enderror

<div data-form-segments="{{ $formId }}">
    <!-- Tab switcher — HANYA tampil di mobile (<768px). Di desktop/tablet
         semua bagian tetap tampil sekaligus seperti sebelumnya (lihat
         md:contents di tiap panel di bawah), jadi form-segments.js tidak
         berpengaruh apa-apa di layar besar. -->
    <div class="md:hidden grid grid-cols-4 gap-1 bg-gray-100 p-1 rounded-lg mb-4">
        <button type="button" data-segment-tab="info" class="px-2 py-2 rounded-md text-xs font-semibold bg-white text-indigo-600 shadow-sm">Info</button>
        <button type="button" data-segment-tab="satuan" class="px-2 py-2 rounded-md text-xs font-semibold text-gray-500">Satuan</button>
        <button type="button" data-segment-tab="stok" class="px-2 py-2 rounded-md text-xs font-semibold text-gray-500">Stok & Harga</button>
        <button type="button" data-segment-tab="lain" class="px-2 py-2 rounded-md text-xs font-semibold text-gray-500">Lainnya</button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div data-segment-panel="info" class="md:contents">
        <!-- Nama -->
        <div class="sm:col-span-2">
            <x-input-label value="Nama Produk" />
            <x-text-input name="name" value="{{ old('name', $product->name ?? '') }}" class="mt-1 block w-full" required autofocus />
        </div>

        <!-- Kategori -->
        <div>
            <x-input-label value="Kategori" />
            <select name="category_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">-- Pilih Kategori --</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id ?? null) == $cat->id)>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- SKU -->
        <div>
            <x-input-label value="SKU (kosongkan untuk auto-generate)" />
            <x-text-input name="sku" value="{{ old('sku', $product->sku ?? '') }}" class="mt-1 block w-full"
                           placeholder="{{ $isEdit ? $product->sku : 'Contoh: PRD-0001' }}" />
        </div>

        <!-- Tipe Tracking -->
        <div class="sm:col-span-2">
            <x-input-label value="Cara Jual Produk Ini" />
            <div class="mt-1 flex gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="tracking_mode" value="unit" data-tracking-mode-radio="{{ $formId }}"
                           @checked($trackingMode === 'unit') {{ $isEdit ? 'disabled' : '' }}>
                    Satuan Diskrit (pcs, dus, renceng, sachet, dll)
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="tracking_mode" value="weight" data-tracking-mode-radio="{{ $formId }}"
                           @checked($trackingMode === 'weight') {{ $isEdit ? 'disabled' : '' }}>
                    Timbang / Curah (kg, gram)
                </label>
            </div>
            @if ($isEdit)
                <input type="hidden" name="tracking_mode" value="{{ $trackingMode }}">
                <p class="mt-1 text-xs text-gray-400">Cara jual tidak bisa diubah setelah produk dibuat (untuk menjaga konsistensi riwayat stok).</p>
            @endif
        </div>
        </div>

        <div data-segment-panel="satuan" class="hidden md:contents">
        <!-- Satuan Produk (dinamis) -->
        <div class="sm:col-span-2" data-unit-section>
            <div class="flex items-center justify-between mb-2">
                <x-input-label value="Satuan Produk" />
                <button type="button" data-unit-add="{{ $formId }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                    + Tambah Satuan
                </button>
            </div>

            <x-barcode-scan-shared :form-id="$formId" />

            <div class="mb-2 rounded-md bg-indigo-50 border border-indigo-100 px-3 py-2 text-xs text-indigo-700">
                Susun dari yang <strong>terbesar di atas</strong> ke <strong>terkecil di bawah</strong>. Baris paling bawah
                otomatis jadi <strong>Satuan Dasar</strong> (dipakai untuk tracking stok) — tidak perlu dipilih manual.
                Isi kolom "Isi" dengan jumlah satuan di <strong>baris tepat di bawahnya</strong>, bukan langsung ke satuan
                dasar (contoh: 1 dus = 24 <em>renceng</em>, bukan 24 sachet — sistem yang menghitung totalnya otomatis,
                lihat pratinjau kecil di bawah tiap kolom "Isi"). Kolom "Beli" menandai satuan default saat
                belanja/restock dari supplier — ini boleh diubah kapan saja.
                @if ($isEdit)
                    Satuan dasar produk ini sudah terkunci dan tidak bisa dipindah/dihapus lagi.
                @endif
            </div>

            <div data-unit-rows="{{ $formId }}" data-counter="{{ $unitRows->count() }}" data-lock-last="{{ $isEdit ? '1' : '0' }}"
                 class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                @foreach ($unitRows as $index => $unit)
                    @include('gudang.produk._unit-row', ['unit' => $unit, 'index' => $index, 'formId' => $formId])
                @endforeach
            </div>
            {{-- Selalu di-render (bukan @if), supaya toggleEmptyHint() di JS bisa
                 menampilkan/menyembunyikannya kapan pun — termasuk kalau user
                 menghapus SEMUA baris satuan yang bisa dihapus di mode edit. --}}
            <p class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-center text-xs text-gray-400 {{ $unitRows->isEmpty() ? '' : 'hidden' }}" data-unit-empty-hint>
                Belum ada satuan. Klik "+ Tambah Satuan" untuk menambahkan (contoh: dus, renceng, sachet — atau kg/gram untuk produk curah).
            </p>
        </div>
        </div>

        <div data-segment-panel="stok" class="hidden md:contents">
        @if (! $isEdit)
        <!-- Stok Awal (hanya saat create) -->
        <div>
            <x-input-label value="Stok Awal (dalam satuan dasar)" />
            <x-text-input type="number" step="0.001" name="initial_stock" value="{{ old('initial_stock', 0) }}" min="0" class="mt-1 block w-full" />
        </div>

        <div>
            <x-input-label value="Estimasi Harga Pokok per Satuan Dasar (opsional)" />
            <x-text-input type="number" name="initial_cost" value="{{ old('initial_cost') }}" min="0" class="mt-1 block w-full" placeholder="Rp" />
            <p class="mt-1 text-xs text-gray-400">Kalau diisi, jadi basis awal harga pokok rata-rata sebelum ada pembelian resmi.</p>
        </div>
    @else
        <div>
            <x-input-label value="Stok Saat Ini" />
            <div class="mt-1 px-3 py-2 rounded-md bg-gray-50 border border-gray-200 text-gray-600 text-sm">
                {{ \App\Support\Number::trim((float) $product->stock) }} {{ $product->baseUnit->unit_name ?? '' }}
            </div>
            <p class="mt-1 text-xs text-gray-400">
                Perubahan stok dikelola di menu <a href="{{ route('gudang.stok.index') }}" class="text-indigo-600 hover:underline">Stok</a> / Pembelian.
            </p>
        </div>

        <div>
            <x-input-label value="Harga Pokok Rata-Rata" />
            <div class="mt-1 px-3 py-2 rounded-md bg-gray-50 border border-gray-200 text-gray-600 text-sm">
                Rp {{ number_format($product->average_cost, 0, ',', '.') }} / {{ $product->baseUnit->unit_name ?? '' }}
            </div>
        </div>
    @endif

    <!-- Ambang batas stok minimum -->
    <div>
        <x-input-label value="Ambang Batas Stok Menipis (satuan dasar)" />
        <x-text-input type="number" step="0.001" name="min_stock" value="{{ old('min_stock', $product->min_stock ?? 5) }}" min="0" class="mt-1 block w-full" required />
    </div>

    <!-- Fractional sale -->
    <div class="flex items-center pt-6">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="hidden" name="allow_fractional_sale" value="0">
            <input type="checkbox" name="allow_fractional_sale" value="1" data-fractional-checkbox="{{ $formId }}"
                   @checked(old('allow_fractional_sale', $product->allow_fractional_sale ?? false))
                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Boleh dijual dengan kuantitas pecahan bebas (misal 0.35 kg)
        </label>
    </div>
        </div>

        <div data-segment-panel="lain" class="hidden md:contents">
    <!-- Foto -->
    <div>
        <x-input-label value="Foto Produk (opsional)" />
        <input type="file" name="image" accept="image/*" data-image-preview="preview-{{ $formId }}"
               class="mt-1 block w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
        <img id="preview-{{ $formId }}" src="{{ $isEdit && $product->image ? Storage::url($product->image) : '' }}"
             class="mt-2 w-20 h-20 object-cover rounded-md border border-gray-200 {{ $isEdit && $product->image ? '' : 'hidden' }}">
    </div>

    <!-- Status Aktif -->
    <div class="flex items-center pt-6">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            {{-- Hidden input SEBELUM checkbox: browser tidak mengirim field checkbox
                 sama sekali kalau di-uncheck, jadi tanpa ini server tidak bisa beda-
                 kan "sengaja di-uncheck" vs "field memang tidak ada". Urutan (hidden
                 dulu, checkbox sesudah) penting: kalau checked, browser kirim "0" lalu
                 "1" untuk name yang sama, dan PHP ambil nilai TERAKHIR = "1". --}}
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active ?? true))
                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Produk aktif (tampil di POS kasir)
        </label>
    </div>

    <!-- Deskripsi -->
    <div class="sm:col-span-2">
        <x-input-label value="Deskripsi (opsional)" />
        <textarea name="description" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $product->description ?? '') }}</textarea>
    </div>
        </div>
    </div>
</div>

<!-- Template baris satuan baru (dipakai JS saat klik "+ Tambah Satuan").
     Struktur & atribut data-* HARUS sama persis dengan _unit-row.blade.php;
     unit-rows.js tidak peduli tabel atau kartu, yang penting atributnya. -->
<template data-unit-row-template="{{ $formId }}">
    <div data-unit-row data-locked="0" class="rounded-lg border border-gray-200 bg-white p-3">
        <div class="flex items-start justify-between gap-2 mb-2">
            <input type="text" data-field="unit_name" placeholder="contoh: renceng" required
                   class="min-w-0 flex-1 rounded-md border-gray-300 text-sm font-semibold focus:border-indigo-500 focus:ring-indigo-500">
            <button type="button" data-unit-remove
                    class="flex-none w-7 h-7 rounded-full bg-red-50 text-red-600 text-sm font-bold leading-none hover:bg-red-100">
                &times;
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2">
            <span class="hidden inline-flex items-center px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-700 text-[11px] font-semibold" data-base-label>
                Satuan Dasar
            </span>
            <label class="inline-flex items-center gap-1.5 text-[11px] font-medium text-gray-500">
                <input type="radio" data-field="is_purchase_unit" class="text-indigo-600 focus:ring-indigo-500">
                Beli Default
            </label>
        </div>

        <div class="grid grid-cols-2 gap-2 mb-2">
            <div>
                <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Isi (&rarr; bawahnya)</label>
                <input type="number" step="0.001" min="0.001" data-field="relative_qty" required
                       class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="qty">
                <span class="block text-[11px] text-gray-400 mt-0.5" data-conversion-hint></span>
            </div>
            <div>
                <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Harga Jual</label>
                <input type="number" min="0" data-field="selling_price" placeholder="opsional"
                       class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
        </div>

        <div>
            <label class="block text-[10.5px] font-medium text-gray-400 mb-0.5">Barcode</label>
            <input type="text" data-field="barcode" data-barcode-row-target placeholder="opsional"
                   class="w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
    </div>
</template>