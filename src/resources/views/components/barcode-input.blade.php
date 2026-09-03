@props(['id', 'name', 'value' => null, 'label' => 'Barcode'])

<div>
    <x-input-label :value="$label" />
    <div class="mt-1 flex gap-2">
        <input type="text" id="{{ $id }}-input" name="{{ $name }}" value="{{ $value }}"
               placeholder="Ketik, scan kamera, atau upload foto"
               class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">

        <button type="button" data-barcode-scan="{{ $id }}"
                class="shrink-0 px-3 py-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-700"
                title="Scan pakai kamera">
            <x-icon name="camera" class="w-5 h-5" />
        </button>

        <label class="shrink-0 px-3 py-2 rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-700 cursor-pointer"
               title="Upload foto barcode">
            <x-icon name="upload" class="w-5 h-5" />
            <input type="file" accept="image/*" class="hidden" data-barcode-upload="{{ $id }}">
        </label>
    </div>
    <p class="mt-1 text-xs text-gray-400">
        Kosongkan jika produk tidak punya barcode — sistem akan generate barcode internal otomatis.
    </p>
</div>

{{-- Modal kamera scan, khusus untuk instance komponen ini (id harus unik per form) --}}
<x-modal.modal name="barcode-camera-{{ $id }}" maxWidth="md">
    <div class="p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-medium text-gray-900">Scan Barcode</h3>
            <button type="button" data-modal-close data-barcode-stop="{{ $id }}" class="text-gray-400 hover:text-gray-600">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </div>
        <div id="{{ $id }}-camera-region" class="rounded-lg overflow-hidden bg-gray-900 min-h-[250px]"></div>
        <p class="text-xs text-gray-400 mt-2">Arahkan kamera ke barcode/QR code produk. Terdeteksi otomatis.</p>
    </div>
</x-modal.modal>