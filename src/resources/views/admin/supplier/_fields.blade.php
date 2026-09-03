@php
    $isEdit = (bool) $supplier;
    // Kalau form INI yang tadi disubmit lalu gagal validasi, pakai old();
    // kalau tidak, pakai data existing (mode edit) atau kosong (mode create).
    $reopening = old('form_id') === $formId;
    $val = fn (string $field, $default = '') => $reopening
        ? old($field, $default)
        : old($field, $supplier->{$field} ?? $default);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label value="Nama Supplier" />
        <x-text-input name="name" value="{{ $val('name') }}" class="mt-1 block w-full" required autofocus placeholder="Contoh: CV Sumber Rejeki" />
    </div>

    <div>
        <x-input-label value="Nama Kontak (opsional)" />
        <x-text-input name="contact_person" value="{{ $val('contact_person') }}" class="mt-1 block w-full" placeholder="Nama sales/PIC" />
    </div>

    <div>
        <x-input-label value="Telepon (opsional)" />
        <x-text-input name="phone" value="{{ $val('phone') }}" class="mt-1 block w-full" placeholder="08xx-xxxx-xxxx" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label value="Alamat (opsional)" />
        <textarea name="address" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ $val('address') }}</textarea>
    </div>

    <div class="sm:col-span-2">
        <x-input-label value="Catatan (opsional)" />
        <textarea name="note" rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                  placeholder="Misal: termin pembayaran, jadwal kirim rutin, dll">{{ $val('note') }}</textarea>
    </div>

    @if ($isEdit)
        <div class="sm:col-span-2 flex items-center">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $supplier->is_active ?? true))
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Supplier aktif (tampil sebagai pilihan saat buat PO baru)
            </label>
        </div>
    @endif
</div>
