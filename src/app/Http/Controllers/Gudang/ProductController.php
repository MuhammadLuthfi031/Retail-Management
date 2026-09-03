<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::with(['category', 'units'])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%")
                        ->orWhereHas('units', fn ($q3) => $q3->where('barcode', $request->search));
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('gudang.produk.index', compact('products', 'categories'));
    }

    public function show(Product $product): View
    {
        $product->load(['category', 'units' => fn ($q) => $q->orderBy('sort_order')]);
        $categories = Category::orderBy('name')->get();   // <- tambahkan ini

        return view('gudang.produk.show', compact('product', 'categories'));   // <- + categories
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedProduct($request);
        $units = $this->extractUnits($request);

        $validated['sku'] = $validated['sku'] ?: $this->generateSku();
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['allow_fractional_sale'] = $request->boolean('allow_fractional_sale', false);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product = DB::transaction(function () use ($validated, $units, $request) {
            /** @var Product $product */
            $product = Product::create($validated);
            $this->syncUnits($product, $units);

            $initialStock = (float) $request->input('initial_stock', 0);
            if ($initialStock > 0) {
                StockMovement::record(
                    product: $product,
                    type: 'in',
                    quantity: $initialStock,
                    userId: auth()->id(),
                    note: 'Stok awal saat produk pertama kali dibuat',
                    unitCost: $request->filled('initial_cost') ? (int) $request->input('initial_cost') : null,
                );
            }

            return $product;
        });

        return redirect()->route('gudang.produk.show', $product)->with('success', "Produk \"{$product->name}\" berhasil ditambahkan.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validatedProduct($request, $product->id);
        $units = $this->extractUnits($request);

        $validated['sku'] = $validated['sku'] ?: $product->sku;
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['allow_fractional_sale'] = $request->boolean('allow_fractional_sale', false);

        // Cara jual (tracking_mode) TIDAK boleh diubah setelah produk dibuat —
        // supaya riwayat stok & transaksi lama tetap konsisten secara historis.
        // Field-nya dikirim disabled/hidden dari form, tapi kita jaga juga di
        // sisi server untuk berjaga-jaga (defense in depth).
        unset($validated['tracking_mode']);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($product, $validated, $units) {
            $product->update($validated);
            $this->syncUnits($product, $units);
        });

        return back()->with('success', "Produk \"{$product->name}\" berhasil diperbarui.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->transactionDetails()->exists()) {
            return back()->with('error', "Produk \"{$product->name}\" tidak bisa dihapus karena sudah pernah terjual. Nonaktifkan saja produk ini jika sudah tidak dijual.");
        }

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete(); // product_units ikut terhapus (cascadeOnDelete)

        return redirect()->route('gudang.produk.index')->with('success', "Produk \"{$product->name}\" berhasil dihapus.");
    }

    /**
     * Validasi field inti produk (di luar array satuan, yang divalidasi
     * terpisah di extractUnits() karena strukturnya dinamis).
     */
    private function validatedProduct(Request $request, ?int $ignoreId = null): array
    {
        $uniqueSku = 'unique:products,sku' . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'tracking_mode' => ['required', 'in:unit,weight'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', $uniqueSku],
            'min_stock' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);
    }

    /**
     * Ambil & validasi array satuan dari request. Nama field radio untuk
     * is_base_unit/is_purchase_unit bersifat dinamis (di-scope per form_id
     * di sisi Blade, supaya tidak bentrok antar modal di halaman yang sama).
     */
    private function extractUnits(Request $request): array
    {
        $formId = $request->input('form_id');
        $rawUnits = $request->input('units', []);
        $baseIndex = $request->input("is_base_unit_index_{$formId}");
        $purchaseIndex = $request->input("is_purchase_unit_index_{$formId}");

        if (empty($rawUnits)) {
            throw ValidationException::withMessages(['units' => 'Minimal harus ada 1 satuan produk.']);
        }
        if ($baseIndex === null || ! isset($rawUnits[$baseIndex])) {
            throw ValidationException::withMessages(['units' => 'Tentukan satu satuan sebagai "Satuan Dasar".']);
        }

        $units = [];
        foreach ($rawUnits as $index => $row) {
            $unitName = trim((string) ($row['unit_name'] ?? ''));
            $conversion = (float) ($row['conversion_to_base'] ?? 0);

            if ($unitName === '' || $conversion <= 0) {
                continue; // lewati baris kosong/tidak valid (misal sempat ditambah lalu dikosongkan)
            }

            $units[] = [
                'id' => $row['id'] ?? null,
                'unit_name' => $unitName,
                'conversion_to_base' => (string) $index === (string) $baseIndex ? 1 : $conversion,
                'selling_price' => ($row['selling_price'] ?? '') !== '' ? (int) $row['selling_price'] : null,
                'barcode' => ($row['barcode'] ?? '') !== '' ? trim($row['barcode']) : null,
                'is_base_unit' => (string) $index === (string) $baseIndex,
                'is_purchase_unit' => (string) $index === (string) $purchaseIndex,
                'sort_order' => count($units),
            ];
        }

        if (empty($units)) {
            throw ValidationException::withMessages(['units' => 'Minimal harus ada 1 satuan produk yang valid.']);
        }

        // Validasi barcode unik (lintas produk lain maupun sesama baris di form ini)
        $barcodes = array_filter(array_column($units, 'barcode'));
        if (count($barcodes) !== count(array_unique($barcodes))) {
            throw ValidationException::withMessages(['units' => 'Ada barcode yang sama dipakai di lebih dari satu satuan pada form ini.']);
        }
        foreach ($barcodes as $index => $barcode) {
            $exists = ProductUnit::where('barcode', $barcode)
                ->when($units[$index]['id'] ?? null, fn ($q) => $q->where('id', '!=', $units[$index]['id']))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['units' => "Barcode \"{$barcode}\" sudah dipakai produk/satuan lain."]);
            }
        }

        return $units;
    }

    /** Simpan/update/hapus baris product_units sesuai data yang dikirim dari form. */
    private function syncUnits(Product $product, array $units): void
    {
        $keepIds = [];

        foreach ($units as $unitData) {
            $id = $unitData['id'] ?? null;
            unset($unitData['id']);

            if ($id) {
                $unit = ProductUnit::find($id);
                $unit?->update($unitData);
                if ($unit) {
                    $keepIds[] = $unit->id;
                }
            } else {
                $unit = $product->units()->create($unitData);
                $keepIds[] = $unit->id;
            }
        }

        // Hapus baris satuan yang tadinya ada tapi sudah dihapus user dari form (mode edit)
        $product->units()->whereNotIn('id', $keepIds)->delete();
    }

    private function generateSku(): string
    {
        $number = (Product::max('id') ?? 0) + 1;
        $sku = 'PRD-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        while (Product::where('sku', $sku)->exists()) {
            $number++;
            $sku = 'PRD-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        }

        return $sku;
    }
}
