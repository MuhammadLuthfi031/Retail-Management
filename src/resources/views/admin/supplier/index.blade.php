<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Supplier') }}
            </h2>
            <button type="button" data-modal-open="create-supplier"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition">
                + Tambah Supplier
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
                           placeholder="Nama, kontak, atau telepon..."
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                @if (request()->anyFilled(['search', 'status']))
                    <a href="{{ route('admin.supplier.index') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                        Reset
                    </a>
                @endif
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kontak</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Alamat</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Riwayat PO</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($suppliers as $supplier)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $supplier->name }}</div>
                                    @if ($supplier->note)
                                        <div class="text-xs text-gray-400">{{ Str::limit($supplier->note, 60) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500">
                                    <div>{{ $supplier->contact_person ?: '—' }}</div>
                                    <div class="text-xs text-gray-400">{{ $supplier->phone ?: '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ Str::limit($supplier->address, 50) ?: '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                        {{ $supplier->purchase_orders_count }} PO
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                        'bg-emerald-100 text-emerald-700' => $supplier->is_active,
                                        'bg-gray-100 text-gray-500' => ! $supplier->is_active,
                                    ])>
                                        {{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                    <button type="button" data-modal-open="edit-supplier-{{ $supplier->id }}"
                                            class="text-indigo-600 hover:text-indigo-900 font-medium">Edit</button>
                                    <button type="button" data-modal-open="delete-supplier-{{ $supplier->id }}"
                                            class="text-red-600 hover:text-red-900 font-medium">Hapus</button>
                                </td>
                            </tr>

                            <!-- Modal Edit -->
                            <x-modal.modal name="edit-supplier-{{ $supplier->id }}">
                                <form method="POST" action="{{ route('admin.supplier.update', $supplier) }}" class="p-6">
                                    @csrf
                                    @method('PUT')
                                    <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Supplier</h3>

                                    @php $formId = 'edit-supplier-' . $supplier->id; @endphp
                                    <input type="hidden" name="form_id" value="{{ $formId }}">
                                    @include('admin.supplier._fields', ['supplier' => $supplier, 'formId' => $formId])

                                    <div class="flex justify-end gap-2 mt-6">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Simpan Perubahan</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Delete -->
                            <x-modal.modal name="delete-supplier-{{ $supplier->id }}">
                                <form method="POST" action="{{ route('admin.supplier.destroy', $supplier) }}" class="p-6">
                                    @csrf
                                    @method('DELETE')
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">Hapus Supplier?</h3>
                                    <p class="text-sm text-gray-500 mb-4">
                                        Supplier "<strong>{{ $supplier->name }}</strong>" akan dihapus permanen.
                                        Kalau supplier ini masih punya riwayat PO, penghapusan akan ditolak — nonaktifkan saja sebagai gantinya.
                                    </p>
                                    <div class="flex justify-end gap-2">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-danger-button>Ya, Hapus</x-danger-button>
                                    </div>
                                </form>
                            </x-modal.modal>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada supplier yang cocok dengan filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $suppliers->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Create -->
    <x-modal.modal name="create-supplier">
        <form method="POST" action="{{ route('admin.supplier.store') }}" class="p-6">
            @csrf
            <h3 class="text-lg font-medium text-gray-900 mb-4">Tambah Supplier</h3>

            <input type="hidden" name="form_id" value="create-supplier">
            @include('admin.supplier._fields', ['supplier' => null, 'formId' => 'create-supplier'])

            <div class="flex justify-end gap-2 mt-6">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan Supplier</x-primary-button>
            </div>
        </form>
    </x-modal.modal>

    {{-- Sama seperti Modul Produk: buka kembali modal yang tadi disubmit kalau
         validasi gagal, supaya isian yang sudah diketik & pesan error terlihat
         jelas — bukannya form kelihatan "hilang" begitu saja. --}}
    @if ($errors->any() && old('form_id'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var trigger = document.querySelector('[data-modal-open="{{ old('form_id') }}"]');
                if (trigger) trigger.click();
            });
        </script>
    @endif
</x-app-layout>
