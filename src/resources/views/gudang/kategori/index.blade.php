<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Kategori Produk') }}
            </h2>
            <button type="button" data-modal-open="create-category"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                + Tambah Kategori
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Search -->
            <form method="GET" class="mb-4">
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Cari kategori..."
                       class="w-full sm:w-72 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Deskripsi</th>
                            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Jumlah Produk</th>
                            <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($categories as $category)
                            <tr>
                                <td class="px-6 py-4 font-medium text-gray-900">{{ $category->name }}</td>
                                <td class="px-6 py-4 text-gray-500">{{ $category->description ?: '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                        {{ $category->products_count }} produk
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button type="button" data-modal-open="edit-category-{{ $category->id }}"
                                            class="text-indigo-600 hover:text-indigo-900 font-medium">
                                        Edit
                                    </button>
                                    <button type="button" data-modal-open="delete-category-{{ $category->id }}"
                                            class="text-red-600 hover:text-red-900 font-medium">
                                        Hapus
                                    </button>
                                </td>
                            </tr>

                            <!-- Modal Edit -->
                            <x-modal.modal name="edit-category-{{ $category->id }}">
                                <form method="POST" action="{{ route('gudang.kategori.update', $category) }}" class="p-6">
                                    @csrf
                                    @method('PUT')
                                    <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Kategori</h3>

                                    <div class="mb-4">
                                        <x-input-label value="Nama Kategori" />
                                        <x-text-input name="name" value="{{ $category->name }}" class="mt-1 block w-full" required />
                                    </div>

                                    <div class="mb-4">
                                        <x-input-label value="Deskripsi (opsional)" />
                                        <textarea name="description" rows="3"
                                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ $category->description }}</textarea>
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-primary-button>Simpan Perubahan</x-primary-button>
                                    </div>
                                </form>
                            </x-modal.modal>

                            <!-- Modal Delete -->
                            <x-modal.modal name="delete-category-{{ $category->id }}">
                                <form method="POST" action="{{ route('gudang.kategori.destroy', $category) }}" class="p-6">
                                    @csrf
                                    @method('DELETE')
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">Hapus Kategori?</h3>
                                    <p class="text-sm text-gray-500 mb-4">
                                        Kategori "<strong>{{ $category->name }}</strong>" akan dihapus permanen.
                                        Tindakan ini tidak bisa dibatalkan.
                                    </p>
                                    <div class="flex justify-end gap-2">
                                        <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                                        <x-danger-button>Ya, Hapus</x-danger-button>
                                    </div>
                                </form>
                            </x-modal.modal>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada kategori. Klik "Tambah Kategori" untuk mulai.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $categories->links() }}
            </div>
        </div>
    </div>

    <!-- Modal Create -->
    <x-modal.modal name="create-category">
        <form method="POST" action="{{ route('gudang.kategori.store') }}" class="p-6">
            @csrf
            <h3 class="text-lg font-medium text-gray-900 mb-4">Tambah Kategori</h3>

            <div class="mb-4">
                <x-input-label value="Nama Kategori" />
                <x-text-input name="name" class="mt-1 block w-full" required autofocus placeholder="Contoh: Makanan Ringan" />
            </div>

            <div class="mb-4">
                <x-input-label value="Deskripsi (opsional)" />
                <textarea name="description" rows="3"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                          placeholder="Keterangan singkat kategori ini"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-secondary-button type="button" data-modal-close>Batal</x-secondary-button>
                <x-primary-button>Simpan Kategori</x-primary-button>
            </div>
        </form>
    </x-modal.modal>
</x-app-layout>