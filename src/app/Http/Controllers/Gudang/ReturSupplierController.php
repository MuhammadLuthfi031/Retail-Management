<?php

namespace App\Http\Controllers\Gudang;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Retur ke supplier dari modal di halaman detail PO — boleh Gudang DAN Admin
 * (group role:admin,gudang). Stok langsung berkurang saat disimpan.
 *
 * Gudang TIDAK boleh melihat uang (pemisahan tugas: Admin pegang data finansial,
 * Gudang pegang fisik), jadi form untuk Gudang tidak memuat harga/nilai SAMA SEKALI
 * (termasuk di atribut HTML); respons JSON juga tidak pernah memuat nilai.
 * Aturan bisnis & konkurensi ada di PurchaseReturnService.
 */
class ReturSupplierController extends Controller
{
    public function __construct(private PurchaseReturnService $service)
    {
    }

    /** Isi form retur (HTML parsial) untuk ditaruh di dalam modal. */
    public function form(PurchaseOrder $pembelian): JsonResponse
    {
        if (! in_array($pembelian->status, PurchaseReturnService::RETURNABLE_STATUSES, true)) {
            return response()->json(['message' => 'PO ini belum ada barang yang diterima, tidak bisa diretur.'], 422);
        }

        $pembelian->load('supplier:id,name');
        $states = $this->service->lineStates($pembelian);

        return response()->json([
            'html' => view('gudang.partials.retur-supplier-form', [
                'po' => $pembelian,
                'states' => $states,
                'canReturn' => $states->contains(fn ($s) => $s['remaining_base_milli'] > 0),
                'idempotencyKey' => (string) Str::uuid(),
                'showValues' => auth()->user()->isAdmin(),
            ])->render(),
        ]);
    }

    public function store(Request $request, PurchaseOrder $pembelian): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'items.*.unit_id' => ['nullable', 'integer'],
        ], [
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.max' => 'Alasan retur maksimal 255 karakter.',
            'items.required' => 'Isi qty retur minimal pada satu barang.',
            'items.*.qty.numeric' => 'Qty retur harus berupa angka.',
            'items.*.qty.min' => 'Qty retur tidak boleh negatif.',
            'items.*.qty.max' => 'Qty retur terlalu besar.',
            'items.*.qty.decimal' => 'Qty retur maksimal 3 angka di belakang koma.',
            'items.*.unit_id.integer' => 'Satuan retur tidak valid.',
        ]);

        $return = $this->service->process(
            po: $pembelian,
            lines: $validated['items'],
            reason: $validated['reason'],
            idempotencyKey: $validated['idempotency_key'],
            userId: (int) auth()->id(),
        );

        $created = $return->wasRecentlyCreated;
        $message = $created
            ? "Retur {$return->return_number} berhasil dicatat. Stok sudah dikurangi."
            : "Retur {$return->return_number} sudah tercatat sebelumnya (tidak dibuat ganda).";

        // Halaman asal di-reload oleh JS; pesan ini tampil lewat <x-alert />.
        session()->flash('success', $message);

        return response()->json([
            'message' => $message,
            'return_number' => $return->return_number,
            'created' => $created,
        ]);
    }
}