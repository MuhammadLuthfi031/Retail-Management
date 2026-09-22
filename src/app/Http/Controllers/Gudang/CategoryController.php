<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = Category::withCount('products')
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('gudang.kategori.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->createWithUniqueSlug($validated);

        return back()->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['name'] !== $category->name) {
            $this->updateWithUniqueSlug($category, $validated);
        } else {
            $category->update($validated);
        }

        return back()->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->with('error', "Kategori \"{$category->name}\" tidak bisa dihapus karena masih memiliki produk terkait.");
        }

        $category->delete();

        return back()->with('success', 'Kategori berhasil dihapus.');
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Simpan Category baru dengan slug yang DIJAMIN unik, walau ada 2
     * admin/gudang yang buat kategori dengan nama sama nyaris bersamaan.
     * Pola retry sama seperti Transaction::createWithUniqueInvoice() /
     * Product::createWithUniqueSku() — bedanya di sini tidak perlu
     * lockForUpdate() dulu (slug bukan angka urut yang bergantung ke baris
     * sebelumnya seperti invoice/PO/SKU), cukup coba lagi dengan uniqueSlug()
     * yang baru kalau constraint unik di DB menolak percobaan sebelumnya.
     */
    private function createWithUniqueSlug(array $validated, int $maxAttempts = 3): Category
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Category::create([
                    ...$validated,
                    'slug' => $this->uniqueSlug($validated['name']),
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000' || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Lanjut ke percobaan berikutnya dengan slug baru.
            }
        }

        throw new \RuntimeException('Gagal membuat kategori dengan slug unik setelah beberapa kali percobaan.');
    }

    /** Sama seperti createWithUniqueSlug(), untuk kasus mengubah nama kategori yang sudah ada. */
    private function updateWithUniqueSlug(Category $category, array $validated, int $maxAttempts = 3): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $category->update([
                    ...$validated,
                    'slug' => $this->uniqueSlug($validated['name'], $category->id),
                ]);

                return;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000' || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Lanjut ke percobaan berikutnya dengan slug baru.
            }
        }

        throw new \RuntimeException('Gagal memperbarui kategori dengan slug unik setelah beberapa kali percobaan.');
    }
}