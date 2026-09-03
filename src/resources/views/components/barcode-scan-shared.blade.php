@props(['formId'])

<div class="flex items-center gap-2 mb-2">
    <span class="text-xs text-gray-500">Scan barcode:</span>
    <button type="button" data-barcode-shared-scan="{{ $formId }}"
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-gray-300 text-xs text-gray-600 hover:bg-gray-50">
        <x-icon name="camera" class="w-4 h-4" /> Kamera
    </button>
    <label class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-gray-300 text-xs text-gray-600 hover:bg-gray-50 cursor-pointer">
        <x-icon name="upload" class="w-4 h-4" /> Upload Foto
        <input type="file" accept="image/*" class="hidden" data-barcode-shared-upload="{{ $formId }}">
    </label>
    <span class="text-xs text-gray-400">— klik dulu kolom barcode baris satuan yang mau diisi</span>
</div>

<x-modal.modal name="barcode-camera-{{ $formId }}" maxWidth="md">
    <div class="p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-medium text-gray-900">Scan Barcode</h3>
            <button type="button" data-modal-close data-barcode-shared-stop="{{ $formId }}" class="text-gray-400 hover:text-gray-600">
                <x-icon name="x" class="w-5 h-5" />
            </button>
        </div>
        <div id="{{ $formId }}-shared-camera-region" class="rounded-lg overflow-hidden bg-gray-900 min-h-[250px]"></div>
        <p class="text-xs text-gray-400 mt-2">Hasil scan otomatis masuk ke kolom barcode yang terakhir Anda klik.</p>
    </div>
</x-modal.modal>