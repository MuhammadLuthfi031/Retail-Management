<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Manajemen User') }}
            </h2>
            <button type="button" data-modal-open="create-user"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                + Tambah User
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Filter -->
            <form method="GET" class="mb-4 bg-white p-4 rounded-lg shadow-sm flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Cari</label>
                    <input type="text" name="search" value="{{ request('search') }}"
                           placeholder="Nama atau email..."
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Role</label>
                    <select name="role" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua Role</option>
                        <option value="admin" @selected(request('role') === 'admin')>Admin</option>
                        <option value="kasir" @selected(request('role') === 'kasir')>Kasir</option>
                        <option value="gudang" @selected(request('role') === 'gudang')>Gudang</option>
                    </select>
                </div>

                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                    <select name="status" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        <option value="active" @selected(request('status') === 'active')>Aktif</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                    </select>
                </div>

                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">
                    Filter
                </button>
                @if (request()->anyFilled(['search', 'role', 'status']))
                    <a href="{{ route('admin.users.index') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                        Reset
                    </a>
                @endif
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($users as $user)
                            @php $isSelf = $user->id === auth()->id(); @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">
                                        {{ $user->name }}
                                        @if ($isSelf)
                                            <span class="text-xs text-gray-400 font-normal">(Anda)</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-400">{{ $user->email }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold',
                                        'bg-indigo-100 text-indigo-700' => $user->role === 'admin',
                                        'bg-emerald-100 text-emerald-700' => $user->role === 'kasir',
                                        'bg-amber-100 text-amber-700' => $user->role === 'gudang',
                                    ])>
                                        {{ ucfirst($user->role) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700' => $user->is_active,
                                        'bg-gray-100 text-gray-500' => ! $user->is_active,
                                    ])>
                                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                    <button type="button" data-modal-open="edit-user-{{ $user->id }}"
                                            class="text-indigo-600 hover:text-indigo-900 font-medium">Edit</button>
                                    <button type="button" data-modal-open="reset-password-user-{{ $user->id }}"
                                            class="text-gray-600 hover:text-gray-900 font-medium">Reset Password</button>

                                    @if ($isSelf)
                                        <span class="text-gray-300 cursor-not-allowed" title="Tidak bisa nonaktifkan akun sendiri">Nonaktifkan</span>
                                    @elseif ($user->is_active)
                                        <button type="button" data-modal-open="deactivate-user-{{ $user->id }}"
                                                class="text-red-600 hover:text-red-900 font-medium">Nonaktifkan</button>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}" class="inline">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="text-emerald-600 hover:text-emerald-900 font-medium">Aktifkan</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>

                            <!-- Modal Edit -->
                            <x-modal.modal name="edit-user-{{ $user->id }}">
                                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="p-6">
                                    @csrf
                                    @method('PUT')
                                    <h3 class="text-lg font-medium text-gray-900 mb-4">Edit User</h3>

                                    @php $formId = 'edit-user-' . $user->id; @endphp
                                    <input type="hidden" name="form_id" value="{{ $formId }}">
                                    @include('admin.users._fields', ['user' => $user, 'formId' => $formId])

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Simpan Perubahan</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Reset Password -->
                            <x-modal.modal name="reset-password-user-{{ $user->id }}" maxWidth="sm">
                                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="p-6">
                                    @csrf
                                    @method('PUT')
                                    <h3 class="text-lg font-medium text-gray-900 mb-1">Reset Password</h3>
                                    <p class="text-sm text-gray-500 mb-4">
                                        Set password baru untuk <strong>{{ $user->name }}</strong>. Sampaikan password ini ke yang bersangkutan secara langsung.
                                    </p>

                                    <div class="space-y-4">
                                        <div>
                                            <x-input-label value="Password Baru" />
                                            <x-text-input type="password" name="password" class="mt-1 block w-full" required autocomplete="new-password" />
                                        </div>
                                        <div>
                                            <x-input-label value="Konfirmasi Password Baru" />
                                            <x-text-input type="password" name="password_confirmation" class="mt-1 block w-full" required autocomplete="new-password" />
                                        </div>
                                    </div>

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Reset Password</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Konfirmasi Nonaktifkan -->
                            @unless ($isSelf)
                                <x-modal.modal name="deactivate-user-{{ $user->id }}" maxWidth="sm">
                                    <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}" class="p-6">
                                        @csrf
                                        @method('PUT')
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">Nonaktifkan User?</h3>
                                        <p class="text-sm text-gray-500 mb-4">
                                            <strong>{{ $user->name }}</strong> tidak akan bisa login lagi sampai diaktifkan ulang.
                                            Data & riwayat transaksi/stok milik user ini tetap aman, tidak terhapus.
                                        </p>
                                        <div class="flex justify-end gap-2">
                                            <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                            <x-danger-button>Ya, Nonaktifkan</x-danger-button>
                                        </div>
                                    </form>
                                </x-modal.modal>
                            @endunless
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada user yang cocok dengan filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $users->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Create -->
    <x-modal.modal name="create-user">
        <form method="POST" action="{{ route('admin.users.store') }}" class="p-6">
            @csrf
            <h3 class="text-lg font-medium text-gray-900 mb-4">Tambah User</h3>

            <input type="hidden" name="form_id" value="create-user">
            @include('admin.users._fields', ['user' => null, 'formId' => 'create-user'])

            <div class="flex justify-end gap-2 mt-6">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan User</x-primary-button>
            </div>
        </form>
    </x-modal.modal>

    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>
