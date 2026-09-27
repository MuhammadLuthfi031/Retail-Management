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

            <!-- ====== Desktop/tablet: tabel (≥768px) ====== -->
            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
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

            <!-- ====== Mobile: kartu (<768px) ====== -->
            <div class="md:hidden space-y-2.5">
                @forelse ($categories as $category)
                    <div class="bg-white border border-gray-200 rounded-lg p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900 truncate">{{ $category->name }}</div>
                                <div class="text-xs text-gray-400 truncate">{{ $category->description ?: 'Tidak ada deskripsi' }}</div>
                            </div>
                            <span class="flex-none inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 whitespace-nowrap">
                                {{ $category->products_count }} produk
                            </span>
                        </div>

                        <div class="flex items-center gap-2 mt-3 pt-3 border-t border-dashed border-gray-200">
                            <button type="button" data-modal-open="edit-category-{{ $category->id }}"
                                    class="flex-1 min-h-[44px] rounded-md text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100">
                                Edit
                            </button>
                            <button type="button" data-sheet-open="category-actions-{{ $category->id }}"
                                    class="w-11 h-11 flex-none inline-flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                                <x-icon name="dots" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-gray-200 rounded-lg py-10 text-center text-gray-400 text-sm">
                        Belum ada kategori. Klik "Tambah Kategori" untuk mulai.
                    </div>
                @endforelse
            </div>

            <!-- ====== Modal & sheet per kategori — 1x per kategori, dipakai
                 bareng oleh tombol tabel & kartu di atas (pola sama dengan
                 Manajemen User) ====== -->
            @foreach ($categories as $category)
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
                <x-modal.modal name="delete-category-{{ $category->id }}" maxWidth="sm">
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

                <!-- Sheet aksi kategori — dipicu tombol "⋯" di kartu mobile.
                     Isinya cuma MENGARAHKAN ke modal delete yang sama di atas
                     (bukan form duplikat), supaya "Hapus" tidak gampang
                     tersentuh tanpa sengaja di layar kecil. -->
                <x-modal.sheet name="category-actions-{{ $category->id }}">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-900 truncate">{{ $category->name }}</h3>
                            <p class="text-xs text-gray-400 truncate">{{ $category->products_count }} produk</p>
                        </div>
                        <button type="button" data-sheet-close class="text-gray-400 hover:text-gray-600 flex-none ml-3">
                            <x-icon name="x" class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="p-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
                        <button type="button" data-modal-open="delete-category-{{ $category->id }}"
                                class="w-full flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium text-red-600 hover:bg-red-50">
                            <x-icon name="trash" class="w-5 h-5" />
                            Hapus Kategori
                        </button>
                    </div>
                </x-modal.sheet>
            @endforeach

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