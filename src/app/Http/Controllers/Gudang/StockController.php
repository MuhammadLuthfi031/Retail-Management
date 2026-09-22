<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Number;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::with(['category', 'units', 'baseUnit'])
            ->active()
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%");
                });
            })
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return view('gudang.stok.index', compact('products', 'categories'));
    }

    public function show(Request $request, Product $product): View
    {
        $product->load('units');

        $movements = $product->stockMovements()
            ->with('user')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('gudang.stok.show', compact('product', 'movements'));
    }

    public function storeOut(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:' . (float) $product->stock],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'quantity.max' => 'Qty keluar tidak boleh melebihi stok yang ada (' . Number::trim((float) $product->stock) . ').',
        ]);

        StockMovement::record(
            product: $product,
            type: 'out',
            quantity: (float) $validated['quantity'],
            userId: auth()->id(),
            note: $validated['note'],
        );

        return back()->with('success', "Stok keluar untuk \"{$product->name}\" berhasil dicatat.");
    }

    public function storeMutation(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:' . (float) $product->stock],
            'note' => ['required', 'string', 'max:500'],
        ], [
            'quantity.max' => 'Qty mutasi tidak boleh melebihi stok yang ada (' . Number::trim((float) $product->stock) . ').',
        ]);

        StockMovement::record(
            product: $product,
            type: 'mutation',
            quantity: (float) $validated['quantity'],
            userId: auth()->id(),
            note: $validated['note'],
        );

        return back()->with('success', "Mutasi stok untuk \"{$product->name}\" berhasil dicatat.");
    }

    public function storeAdjustment(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'physical_stock' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Kunci baris produk DULU, baru hitung selisih dari stok yang benar-benar
        // TERKINI — bukan dari $product->stock hasil route-model-binding yang
        // di-resolve SEBELUM transaksi ini mulai (bisa basi, mis. sudah lewat
        // beberapa menit sejak gudang buka form & mulai hitung fisik). Kalau
        // ada transaksi lain (penjualan, stok keluar, dll) yang mengubah stok
        // produk ini persis di antara gudang mulai menghitung fisik dan submit
        // form, selisih yang dihitung dari data basi akan SALAH — hasil akhir
        // stok tersimpan jadi tidak sama dengan angka fisik yang sebenarnya
        // diinput, padahal itu justru tujuan opname (mengoreksi ke angka fisik
        // yang benar). Pola locking sama seperti PurchaseReceiptController.
        return DB::transaction(function () use ($validated, $product) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $selisih = round((float) $validated['physical_stock'] - (float) $locked->stock, 3);

            if ($selisih === 0.0) {
                return back()->with('success', "Stok fisik \"{$locked->name}\" sudah sesuai dengan sistem, tidak ada penyesuaian yang dicatat.");
            }

            $note = $validated['note'] ?: 'Hasil stok opname';
            $note .= $selisih > 0
                ? " (stok fisik lebih banyak {$selisih} dari catatan sistem)"
                : ' (stok fisik lebih sedikit ' . abs($selisih) . ' dari catatan sistem)';

            StockMovement::record(
                product: $locked,
                type: 'adjustment',
                quantity: $selisih, // boleh negatif, lihat catatan di StockMovement::record()
                userId: auth()->id(),
                note: $note,
            );

            return back()->with('success', "Stok opname untuk \"{$locked->name}\" berhasil dicatat. Selisih: {$selisih}.");
        });
    }
}