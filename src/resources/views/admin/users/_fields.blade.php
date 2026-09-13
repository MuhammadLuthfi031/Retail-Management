@php
    $isEdit = (bool) $user;
    // Kalau form INI yang tadi disubmit lalu gagal validasi, pakai old();
    // kalau tidak, pakai data existing (mode edit) atau kosong (mode create).
    $reopening = old('form_id') === $formId;
    $val = fn (string $field, $default = '') => $reopening
        ? old($field, $default)
        : old($field, $user->{$field} ?? $default);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label value="Nama" />
        <x-text-input name="name" value="{{ $val('name') }}" class="mt-1 block w-full" required autofocus placeholder="Nama lengkap karyawan" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label value="Email" />
        <x-text-input type="email" name="email" value="{{ $val('email') }}" class="mt-1 block w-full" required placeholder="dipakai untuk login" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label value="Role" />
        <select name="role" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            <option value="" disabled @selected($val('role') === '')>Pilih role...</option>
            <option value="admin" @selected($val('role') === 'admin')>Admin</option>
            <option value="kasir" @selected($val('role') === 'kasir')>Kasir</option>
            <option value="gudang" @selected($val('role') === 'gudang')>Gudang</option>
        </select>
    </div>

    @unless ($isEdit)
        <div>
            <x-input-label value="Password" />
            <x-text-input type="password" name="password" class="mt-1 block w-full" required autocomplete="new-password" />
        </div>
        <div>
            <x-input-label value="Konfirmasi Password" />
            <x-text-input type="password" name="password_confirmation" class="mt-1 block w-full" required autocomplete="new-password" />
        </div>
    @else
        <div class="sm:col-span-2 text-xs text-gray-400 -mt-1">
            Untuk ganti password, pakai tombol "Reset Password" di baris user ini (bukan lewat form Edit).
        </div>
    @endunless
</div>
