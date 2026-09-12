<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiwayatController extends Controller
{
    /**
     * Riwayat transaksi milik kasir yang sedang login sendiri (§4.2 spek:
     * "Kasir lihat riwayat transaksi miliknya sendiri"). Admin yang buka
     * halaman ini (route-nya diizinkan utk admin juga) tetap cuma lihat
     * transaksi ATAS NAMA DIA SENDIRI di sini — laporan lintas-kasir untuk
     * semua transaksi adalah bagian Modul Admin (§7.3, belum dikerjakan),
     * bukan halaman ini.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
        ]);

        $transactions = Transaction::query()
            ->where('user_id', auth()->id())
            ->withCount('details')
            ->when($validated['dari'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($validated['sampai'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('kasir.riwayat', [
            'transactions' => $transactions,
            'filters' => $validated,
        ]);
    }

    /**
     * Halaman cetak struk — dipakai 2 alur: langsung setelah checkout
     * berhasil (dari modal pembayaran POS), dan cetak ulang dari Riwayat.
     * Kasir cuma boleh buka struk transaksi miliknya sendiri; Admin boleh
     * semua (berguna nanti utk fitur void/cancel di Modul Admin §7.5).
     */
    public function struk(Transaction $transaction): View
    {
        abort_unless(
            auth()->user()->isAdmin() || $transaction->user_id === auth()->id(),
            403,
            'Anda tidak punya akses ke struk transaksi ini.'
        );

        $transaction->load(['details', 'user']);

        return view('kasir.struk', compact('transaction'));
    }
}
