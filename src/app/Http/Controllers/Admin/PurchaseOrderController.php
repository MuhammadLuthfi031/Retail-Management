<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $purchaseOrders = PurchaseOrder::with('supplier')
            ->withCount('items')
            ->when($request->search, fn ($q) => $q->where('po_number', 'like', "%{$request->search}%"))
            ->when($request->supplier_id, fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $suppliers = Supplier::active()->orderBy('name')->get();
        $products = $this->productsForForm();

        return view('admin.pembelian.index', compact('purchaseOrders', 'suppliers', 'products'));
    }

    public function show(PurchaseOrder $pembelian): View
    {
        $pembelian->load(['supplier', 'createdBy', 'items.product', 'items.productUnit', 'items.receivedBy']);
        $suppliers = Supplier::active()->orderBy('name')->get();
        $products = $this->productsForForm();

        return view('admin.pembelian.show', ['po' => $pembelian, 'suppliers' => $suppliers, 'products' => $products]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedHeader($request);
        $items = $this->extractItems($request);

        $po = DB::transaction(function () use ($validated, $items) {
            $po = PurchaseOrder::create([
                ...$validated,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'created_by' => auth()->id(),
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'total_amount' => collect($items)->sum('subtotal'),
            ]);

            foreach ($items as $item) {
                $po->items()->create($item);
            }

            return $po;
        });

        return redirect()->route('admin.pembelian.show', $po)->with('success', "PO \"{$po->po_number}\" berhasil dibuat sebagai draft.");
    }

    public function update(Request $request, PurchaseOrder $pembelian): RedirectResponse
    {
        if ($pembelian->status !== 'draft') {
            return back()->with('error', 'PO yang sudah dipesan/diterima tidak bisa diedit lagi. Batalkan & buat PO baru kalau perlu revisi.');
        }

        $validated = $this->validatedHeader($request);
        $items = $this->extractItems($request);

        DB::transaction(function () use ($pembelian, $validated, $items) {
            $pembelian->update([
                ...$validated,
                'total_amount' => collect($items)->sum('subtotal'),
            ]);

            $keepIds = [];
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                unset($item['id']);

                if ($id) {
                    $poItem = PurchaseOrderItem::find($id);
                    $poItem?->update($item);
                    if ($poItem) {
                        $keepIds[] = $poItem->id;
                    }
                } else {
                    $keepIds[] = $pembelian->items()->create($item)->id;
                }
            }
            $pembelian->items()->whereNotIn('id', $keepIds)->delete();
        });

        return back()->with('success', "PO \"{$pembelian->po_number}\" berhasil diperbarui.");
    }

    public function markOrdered(PurchaseOrder $pembelian): RedirectResponse
    {
        if ($pembelian->status !== 'draft') {
            return back()->with('error', 'Hanya PO berstatus draft yang bisa ditandai "Dipesan".');
        }

        $pembelian->update(['status' => 'ordered']);

        return back()->with('success', "PO \"{$pembelian->po_number}\" ditandai sudah dipesan ke supplier. Sekarang muncul di daftar konfirmasi penerimaan Gudang.");
    }

    public function updatePaymentStatus(Request $request, PurchaseOrder $pembelian): RedirectResponse
    {
        $validated = $request->validate([
            'payment_status' => ['required', 'in:unpaid,partial,paid'],
        ]);

        $pembelian->update($validated);

        return back()->with('success', 'Status pembayaran berhasil diperbarui.');
    }

    public function cancel(PurchaseOrder $pembelian): RedirectResponse
    {
        $anyReceived = $pembelian->items()->where('quantity_received', '>', 0)->exists();

        if ($anyReceived) {
            return back()->with('error', 'PO ini sudah ada barang yang diterima sebagian, tidak bisa dibatalkan lagi. Gunakan menu Stok untuk penyesuaian kalau perlu.');
        }

        if (! in_array($pembelian->status, ['draft', 'ordered'], true)) {
            return back()->with('error', 'PO ini tidak bisa dibatalkan.');
        }

        $pembelian->update(['status' => 'cancelled']);

        return back()->with('success', "PO \"{$pembelian->po_number}\" dibatalkan.");
    }

    public function destroy(PurchaseOrder $pembelian): RedirectResponse
    {
        if ($pembelian->status !== 'draft') {
            return back()->with('error', 'Hanya PO berstatus draft yang bisa dihapus. Gunakan "Batalkan" untuk PO yang sudah dipesan.');
        }

        $pembelian->delete(); // items ikut terhapus (cascadeOnDelete)

        return redirect()->route('admin.pembelian.index')->with('success', 'PO berhasil dihapus.');
    }

    private function validatedHeader(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function extractItems(Request $request): array
    {
        $rawItems = $request->input('items', []);

        if (empty($rawItems)) {
            throw ValidationException::withMessages(['items' => 'Minimal harus ada 1 item produk di PO.']);
        }

        $items = [];
        foreach ($rawItems as $row) {
            $productId = $row['product_id'] ?? null;
            $unitId = $row['product_unit_id'] ?? null;
            $qty = (float) ($row['quantity_ordered'] ?? 0);
            $price = (int) ($row['unit_price'] ?? 0);

            if (! $productId || ! $unitId || $qty <= 0) {
                continue;
            }

            $items[] = [
                'id' => $row['id'] ?? null,
                'product_id' => $productId,
                'product_unit_id' => $unitId,
                'quantity_ordered' => $qty,
                'unit_price' => $price,
                'subtotal' => (int) round($qty * $price),
            ];
        }

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'Minimal harus ada 1 item produk yang valid (produk, satuan, qty, dan harga wajib diisi).']);
        }

        return $items;
    }

    /** Data produk + satuan-satuannya, untuk dipakai JS mengisi dropdown satuan pembelian secara dinamis. */
    private function productsForForm()
    {
        return Product::with(['units' => fn ($q) => $q->orderBy('sort_order')])
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'units' => $p->units->map(fn ($u) => [
                    'id' => $u->id,
                    'unit_name' => $u->unit_name,
                    'is_purchase_unit' => (bool) $u->is_purchase_unit,
                ]),
            ]);
    }
}
