<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
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

        $pembelian->load(['supplier', 'items.product', 'items.productUnit', 'receipts.receivedBy']);

        return view('gudang.pembelian.show', ['po' => $pembelian]);
    }

    public function store(Request $request, PurchaseOrder $pembelian): RedirectResponse
    {
        if (! in_array($pembelian->status, ['ordered', 'partially_received'], true)) {
            return back()->with('error', 'PO ini tidak sedang menunggu penerimaan.');
        }

        // Validasi bukti foto DI LUAR transaksi (fail fast) — supaya gudang
        // langsung tahu kalau lupa upload foto, sebelum sistem repot-repot
        // memproses qty/stok. PENTING (keamanan): `mimes:` eksplisit, BUKAN
        // rule `image` generik (svg bisa stored-XSS) — sama seperti pola
        // upload foto produk & bukti pembayaran.
        $request->validate([
            'proof' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $rawQuantities = $request->input('received', []); // [po_item_id => qty_diterima_sekarang]
        $anyProcessed = false;

        DB::transaction(function () use ($request, $pembelian, $rawQuantities, &$anyProcessed) {
            // PENTING (race condition / double-submit): kunci ULANG PO ini beserta
            // semua item-nya DI SINI, di dalam transaksi — bukan pakai instance
            // $pembelian dari route-model-binding yang di-resolve SEBELUM transaksi
            // dimulai (dan sebelum ada lock apa pun). Tanpa ini, klik dobel tombol
            // "Konfirmasi" atau 2 tab browser bisa membuat kedua request sama-sama
            // baca quantity_received yang SAMA (belum ter-update satu sama lain),
            // sama-sama lolos validasi "tidak melebihi sisa" di bawah, dan qty
            // diterima tercatat 2x untuk 1 kali barang datang secara fisik.
            $locked = PurchaseOrder::whereKey($pembelian->id)->lockForUpdate()->firstOrFail();
            $items = $locked->items()->lockForUpdate()->with(['product', 'productUnit'])->get();

            foreach ($items as $item) {
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

                // Total biaya batch ini SELALU exact (harga beli x qty yang benar-benar
                // diterima, dalam satuan pembelian aslinya) — tidak ada pembagian sama
                // sekali, jadi tidak ada celah pembulatan di angka ini. Ini yang dipakai
                // sbg basis recalculateAverageCost (lihat catatan di StockMovement::record()
                // soal kenapa TIDAK boleh pakai unitCostPerBase x qtyBase di sini — itu
                // akan membawa balik pembulatan yang barusan dihindari).
                $totalCostThisReceipt = $item->unit_price * $qtyNow;

                // unitCostPerBase HANYA untuk jejak audit di stock_movements.unit_cost
                // (angka "harga pokok per satuan dasar saat itu" yang enak dibaca manusia
                // di riwayat pergerakan stok) — TIDAK lagi dipakai utk hitung rata-rata.
                $unitCostPerBase = (int) round($item->unit_price / max($conversion, 0.001));

                StockMovement::record(
                    product: $item->product,
                    type: 'in',
                    quantity: $qtyBase,
                    userId: auth()->id(),
                    reference: "PO:{$locked->po_number}",
                    note: "Penerimaan barang PO {$locked->po_number} ({$qtyNow} {$item->productUnit->unit_name})",
                    unitCost: $unitCostPerBase,
                    totalCost: $totalCostThisReceipt,
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

            // File baru disimpan ke disk DI SINI (setelah semua item lolos
            // validasi qty di atas) — supaya kalau ada item yang gagal
            // (throw ValidationException di tengah loop), tidak ada file
            // ke-upload sia-sia yang jadi sampah tanpa baris DB penunjuknya.
            $proofPath = $request->file('proof')->store('purchase-receipts', 'public');

            PurchaseOrderReceipt::create([
                'purchase_order_id' => $locked->id,
                'proof_path' => $proofPath,
                'received_by' => auth()->id(),
            ]);

            $locked->refresh();
            $allFullyReceived = $locked->items->every(fn ($i) => $i->isFullyReceived());
            $anyReceived = $locked->items->contains(fn ($i) => $i->quantity_received > 0);

            $locked->update([
                'status' => $allFullyReceived ? 'received' : ($anyReceived ? 'partially_received' : $locked->status),
            ]);
        });

        return redirect()->route('gudang.pembelian.show', $pembelian)
            ->with('success', "Penerimaan barang untuk PO \"{$pembelian->po_number}\" berhasil dicatat. Stok & harga pokok rata-rata sudah diperbarui.");
    }
}