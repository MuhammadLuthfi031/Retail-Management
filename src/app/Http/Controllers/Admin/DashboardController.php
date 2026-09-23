<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dashboard Admin (§7.1 spesifikasi). Prinsip desain: dashboard ini untuk
 * "sekali lihat langsung paham", BUKAN alat analisis mendalam (itu sudah ada
 * tempatnya di Modul Laporan). Karena itu rentang waktu grafik/list di sini
 * sengaja dibatasi cuma 2 pilihan (7/30 hari), bukan filter tanggal bebas.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $days = in_array((int) $request->query('days'), [7, 30], true) ? (int) $request->query('days') : 7;
        $from = Carbon::today()->subDays($days - 1);
        $to = Carbon::today();

        return view('admin.dashboard', [
            'days' => $days,
            'kpi' => $this->kpiHariIni(),
            'grafikPenjualan' => $this->grafikPenjualan($from, $to),
            'grafikBarangMasuk' => $this->grafikBarangMasuk($from, $to),
            'produkTerlaris' => $this->produkTerlaris($from, $to),
            'riwayatTerbaru' => $this->riwayatTerbaru(),
            'stokMenipis' => $this->stokMenipis(),
        ]);
    }

    /** Ringkasan HARI INI — selalu hari ini, tidak ikut toggle 7/30 hari. */
    private function kpiHariIni(): array
    {
        $base = fn () => Transaction::where('status', 'completed')->whereDate('created_at', Carbon::today());

        $jumlahTransaksi = $base()->count();
        $omzet = (int) $base()->sum('grand_total');

        return [
            'omzet' => $omzet,
            'jumlah_transaksi' => $jumlahTransaksi,
            'rata_rata' => $jumlahTransaksi > 0 ? (int) round($omzet / $jumlahTransaksi) : 0,
            'stok_menipis_count' => Product::active()->lowStock()->count(),
        ];
    }

    /** Tren omzet harian (transaksi selesai) untuk grafik. */
    private function grafikPenjualan(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::where('status', 'completed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('DATE(created_at) as tanggal, SUM(grand_total) as total')
            ->groupBy('tanggal')
            ->get();

        return $this->buildDailySeries($rows, $from, $to);
    }

    /**
     * Tren NILAI barang masuk harian — dihitung LANGSUNG dari
     * PurchaseOrderItem (quantity_received x unit_price), dikelompokkan per
     * tanggal received_at (kapan gudang konfirmasi penerimaan).
     *
     * SENGAJA TIDAK pakai StockMovement type='in': kolom itu juga dipakai
     * untuk "stok awal saat produk pertama kali dibuat" (lihat
     * ProductController::store()), yang BUKAN barang masuk dari pembelian —
     * kalau ikut terhitung, grafik ini akan salah baca tiap kali ada produk
     * baru ditambahkan (melonjak palsu, bukan cerminan aktivitas pembelian
     * yang sesungguhnya). Query ke PurchaseOrderItem langsung menghindari
     * ambiguitas itu sama sekali.
     */
    private function grafikBarangMasuk(Carbon $from, Carbon $to): array
    {
        $rows = PurchaseOrderItem::query()
            ->whereNotNull('received_at')
            ->where('quantity_received', '>', 0)
            ->whereDate('received_at', '>=', $from)
            ->whereDate('received_at', '<=', $to)
            ->selectRaw('DATE(received_at) as tanggal, SUM(quantity_received * unit_price) as total')
            ->groupBy('tanggal')
            ->get();

        return $this->buildDailySeries($rows, $from, $to);
    }

    /** Isi tanggal yang kosong dengan 0, supaya grafik tidak bolong/miring. */
    private function buildDailySeries(Collection $rows, Carbon $from, Carbon $to): array
    {
        $byDate = $rows->keyBy('tanggal');
        $labels = [];
        $values = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('d M');
            $values[] = isset($byDate[$key]) ? (int) round((float) $byDate[$key]->total) : 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** Top 5 produk berdasar OMZET (bukan qty mentah — qty tidak bisa dibandingkan antar produk beda satuan). */
    private function produkTerlaris(Carbon $from, Carbon $to): Collection
    {
        return TransactionDetail::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->where('transactions.status', 'completed')
            ->whereDate('transactions.created_at', '>=', $from)
            ->whereDate('transactions.created_at', '<=', $to)
            ->selectRaw('transaction_details.product_name, SUM(transaction_details.subtotal) as total_omzet, SUM(transaction_details.quantity) as total_qty')
            ->groupBy('transaction_details.product_name')
            ->orderByDesc('total_omzet')
            ->limit(5)
            ->get();
    }

    /** Aktivitas terbaru — selalu "10 transaksi terakhir", tidak ikut toggle periode. */
    private function riwayatTerbaru(): Collection
    {
        return Transaction::with('user')
            ->where('status', 'completed')
            ->latest()
            ->limit(10)
            ->get();
    }

    /** Maks 10 ditampilkan; hitung total lewat kpi.stok_menipis_count kalau lebih dari itu. */
    private function stokMenipis(): Collection
    {
        // with('units'): formatStock() di view dashboard butuh ini (ambil
        // satuan dasar dari collection units yang sudah ter-load) — kelewat
        // saat preventLazyLoading() diaktifkan, baru ketahuan dari error di
        // production. Pola sama seperti StockController::index().
        return Product::with('units')->active()->lowStock()->orderBy('name')->limit(10)->get();
    }
}