<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Daftar, detail, dan penyelesaian retur ke supplier — HANYA Admin (data finansial).
 * Membuat retur dilakukan lewat modal di halaman detail PO (Gudang\ReturSupplierController).
 */
class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service)
    {
    }

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['menunggu', 'selesai', 'semua'])],
            'cari' => ['nullable', 'string', 'max:50'],
        ]);

        $tab = $validated['tab'] ?? 'menunggu';
        $cari = $validated['cari'] ?? '';

        // Ringkasan "belum diganti supplier" — SELALU dari seluruh data (bukan hanya tab/pencarian aktif).
        $pending = PurchaseReturn::where('status', 'pending')
            ->toBase()
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(total_value), 0) as nilai')
            ->first();

        // Escape wildcard LIKE supaya "%" / "_" dicari apa adanya.
        $needle = addcslashes($cari, '%_\\');

        $returns = PurchaseReturn::query()
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'user:id,name'])
            ->withCount('items')
            ->when($tab === 'menunggu', fn ($q) => $q->where('status', 'pending'))
            ->when($tab === 'selesai', fn ($q) => $q->where('status', 'settled'))
            ->when($cari !== '', fn ($q) => $q->where(function ($w) use ($needle) {
                $w->where('return_number', 'like', "%{$needle}%")
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->where('po_number', 'like', "%{$needle}%"));
            }))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.retur-pembelian.index', [
            'returns' => $returns,
            'tab' => $tab,
            'cari' => $cari,
            'pendingCount' => (int) $pending->jumlah,
            'pendingValue' => (int) $pending->nilai,
        ]);
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        $purchaseReturn->load(['items', 'purchaseOrder.supplier', 'user:id,name', 'settledBy:id,name']);

        return view('admin.retur-pembelian.show', ['return' => $purchaseReturn]);
    }

    public function settle(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $validated = $request->validate([
            'settlement_type' => ['required', Rule::in(PurchaseReturnService::SETTLEMENT_TYPES)],
            'settlement_note' => ['nullable', 'string', 'max:255'],
        ], [
            'settlement_type.required' => 'Pilih jenis penyelesaian.',
            'settlement_type.in' => 'Jenis penyelesaian tidak valid.',
            'settlement_note.max' => 'Catatan maksimal 255 karakter.',
        ]);

        $return = $this->service->settle(
            $purchaseReturn,
            $validated['settlement_type'],
            $validated['settlement_note'] ?? null,
            (int) auth()->id(),
        );

        $label = ['refund' => 'refund dari supplier', 'credit' => 'potong tagihan'][$return->settlement_type];

        return redirect()->route('admin.retur-pembelian.show', $return)
            ->with('success', "Retur {$return->return_number} ditandai selesai ({$label}).");
    }
}