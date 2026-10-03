<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesReturn;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar & detail retur penjualan — HANYA Admin (melihat retur dari SEMUA kasir
 * dan mencari transaksi milik kasir mana pun). Memproses retur dilakukan lewat
 * modal di Riwayat Transaksi (Kasir\ReturController), bukan di sini.
 */
class SalesReturnController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'cari' => ['nullable', 'string', 'max:50'],
        ]);

        $hasil = null;
        if (filled($validated['cari'] ?? null)) {
            // Escape wildcard LIKE supaya "%" / "_" yang diketik admin dicari apa adanya.
            $needle = addcslashes($validated['cari'], '%_\\');

            $hasil = Transaction::query()
                ->where('status', 'completed')
                ->where('invoice_number', 'like', "%{$needle}%")
                ->with(['user:id,name', 'details' => fn ($q) => $q->withSum('returnItems', 'quantity')])
                ->latest()
                ->limit(10)
                ->get();
        }

        $returns = SalesReturn::query()
            ->with(['transaction:id,invoice_number', 'user:id,name'])
            ->withCount('items')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.retur-penjualan.index', [
            'hasil' => $hasil,
            'cari' => $validated['cari'] ?? '',
            'returns' => $returns,
        ]);
    }

    public function show(SalesReturn $salesReturn): View
    {
        $salesReturn->load(['items', 'transaction', 'user:id,name']);

        return view('admin.retur-penjualan.show', ['return' => $salesReturn]);
    }
}