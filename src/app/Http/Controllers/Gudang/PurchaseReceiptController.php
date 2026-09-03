<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $purchaseOrders = PurchaseOrder::with('supplier')
            ->withCount('items')
            ->whereIn('status', ['ordered', 'partially_received'])
            ->when($request->search, fn ($q) => $q->where('po_number', 'like', "%{$request->search}%"))
            ->orderBy('expected_date')
            ->paginate(10)
            ->withQueryString();

        return view('gudang.pembelian.index', compact('purchaseOrders'));
    }

    public function show(PurchaseOrder $pembelian): View|RedirectResponse
    {
        if (! in_array($pembelian->status, ['ordered', 'partially_received'], true)) {
            return redirect()->route('gudang.pembelian.index')
                ->with('error', "PO \"{$pembelian->po_number}\" tidak sedang menunggu penerimaan (status: {$pembelian->status}).");
        }

        $pembelian->load(['supplier', 'items.product', 'items.productUnit']);

        return view('gudang.pembelian.show', ['po' => $pembelian]);
    }

    public function store(Request $request, PurchaseOrder $pembelian): RedirectResponse
    {
        if (! in_array($pembelian->status, ['ordered', 'partially_received'], true)) {
            return back()->with('error', 'PO ini tidak sedang menunggu penerimaan.');
        }

        $pembelian->load('items.product', 'items.productUnit');

        $rawQuantities = $request->input('received', []); // [po_item_id => qty_diterima_sekarang]
        $anyProcessed = false;

        DB::transaction(function () use ($pembelian, $rawQuantities, &$anyProcessed) {
            foreach ($pembelian->items as $item) {
                $qtyNow = (float) ($rawQuantities[$item->id] ?? 0);

                if ($qtyNow <= 0) {
                    continue;
                }

                $remaining = $item->remainingQuantity();
                if ($qtyNow > $remaining) {
                    throw ValidationException::withMessages([
                        'received' => "Qty diterima untuk \"{$item->product->name}\" ({$qtyNow}) melebihi sisa yang dipesan ({$remaining}).",
                    ]);
                }

                $anyProcessed = true;
                $conversion = (float) $item->productUnit->conversion_to_base;
                $qtyBase = $qtyNow * $conversion;
                $unitCostPerBase = (int) round($item->unit_price / max($conversion, 0.001));

                StockMovement::record(
                    product: $item->product,
                    type: 'in',
                    quantity: $qtyBase,
                    userId: auth()->id(),
                    reference: "PO:{$pembelian->po_number}",
                    note: "Penerimaan barang PO {$pembelian->po_number} ({$qtyNow} {$item->productUnit->unit_name})",
                    unitCost: $unitCostPerBase,
                );

                $item->update([
                    'quantity_received' => $item->quantity_received + $qtyNow,
                    'received_by' => auth()->id(),
                    'received_at' => now(),
                ]);
            }

            if (! $anyProcessed) {
                throw ValidationException::withMessages(['received' => 'Isi minimal 1 qty penerimaan sebelum konfirmasi.']);
            }

            $pembelian->refresh();
            $allFullyReceived = $pembelian->items->every(fn ($i) => $i->isFullyReceived());
            $anyReceived = $pembelian->items->contains(fn ($i) => $i->quantity_received > 0);

            $pembelian->update([
                'status' => $allFullyReceived ? 'received' : ($anyReceived ? 'partially_received' : $pembelian->status),
            ]);
        });

        return redirect()->route('gudang.pembelian.show', $pembelian)
            ->with('success', "Penerimaan barang untuk PO \"{$pembelian->po_number}\" berhasil dicatat. Stok & harga pokok rata-rata sudah diperbarui.");
    }
}
