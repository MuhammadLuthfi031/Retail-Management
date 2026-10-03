<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\SalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Retur penjualan dari modal di Riwayat Transaksi — boleh Kasir DAN Admin
 * (group role:admin,kasir). Kasir hanya boleh meretur transaksi MILIKNYA
 * sendiri (aturan yang sama dengan struk & daftar Riwayat); Admin boleh semua.
 *
 * Kedua endpoint selalu membalas JSON karena dipanggil dari JS modal
 * (resources/js/retur-modal.js). Aturan bisnis & konkurensi ada di
 * SalesReturnService; controller ini hanya otorisasi, validasi bentuk input,
 * dan menyajikan hasil.
 */
class ReturController extends Controller
{
    public function __construct(private SalesReturnService $service)
    {
    }

    /** Isi form retur (HTML parsial) untuk ditaruh di dalam modal. */
    public function form(Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($transaction);

        if ($transaction->status !== 'completed') {
            return response()->json(['message' => 'Transaksi ini tidak berstatus selesai, tidak bisa diretur.'], 422);
        }

        $transaction->load('user:id,name');
        $states = $this->service->lineStates($transaction);

        return response()->json([
            'html' => view('kasir.partials.retur-form', [
                'transaction' => $transaction,
                'states' => $states,
                'canReturn' => $states->contains(fn ($s) => $s['remaining_milli'] > 0),
                'idempotencyKey' => (string) Str::uuid(),
            ])->render(),
        ]);
    }

    public function store(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($transaction);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'refund_method' => ['required', Rule::in(SalesReturnService::REFUND_METHODS)],
            'reason' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'items.*.condition' => ['nullable', Rule::in(SalesReturnService::CONDITIONS)],
        ], [
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.max' => 'Alasan retur maksimal 255 karakter.',
            'refund_method.required' => 'Pilih metode pengembalian uang.',
            'refund_method.in' => 'Metode pengembalian uang tidak valid.',
            'items.required' => 'Isi qty retur minimal pada satu barang.',
            'items.*.qty.numeric' => 'Qty retur harus berupa angka.',
            'items.*.qty.min' => 'Qty retur tidak boleh negatif.',
            'items.*.qty.max' => 'Qty retur terlalu besar.',
            'items.*.qty.decimal' => 'Qty retur maksimal 3 angka di belakang koma.',
            'items.*.condition.in' => 'Kondisi barang tidak valid.',
        ]);

        $return = $this->service->process(
            transaction: $transaction,
            lines: $validated['items'],
            refundMethod: $validated['refund_method'],
            reason: $validated['reason'],
            idempotencyKey: $validated['idempotency_key'],
            userId: (int) auth()->id(),
        );

        $created = $return->wasRecentlyCreated;
        $message = $created
            ? "Retur {$return->return_number} berhasil dicatat."
            : "Retur {$return->return_number} sudah tercatat sebelumnya (tidak dibuat ganda).";

        // Halaman asal di-reload oleh JS; pesan ini tampil lewat <x-alert />.
        session()->flash('success', $message);

        return response()->json([
            'message' => $message,
            'return_number' => $return->return_number,
            'created' => $created,
        ]);
    }

    /** Kasir hanya miliknya sendiri; Admin semua (pola sama dengan RiwayatController::struk). */
    private function authorizeTransaction(Transaction $transaction): void
    {
        abort_unless(
            auth()->user()->isAdmin() || $transaction->user_id === auth()->id(),
            403,
            'Anda tidak punya akses ke transaksi ini.'
        );
    }
}