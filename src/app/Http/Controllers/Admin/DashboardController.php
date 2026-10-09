<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Services\SalesReturnReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dashboard Admin (§7.1 spesifikasi). Angka penjualan di sini BERSIH: dikurangi retur pelanggan
 * (menurut tanggal retur) lewat SalesReturnReport — sama persis dengan Modul Laporan.
 *
 * Prinsip desain: dashboard ini untuk
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
        // endOfDay() — BUKAN sekadar Carbon::today() (=00:00:00) — supaya
        // whereBetween() di 3 method grafik di bawah tidak kehilangan
        // transaksi hari ini yang timestamp-nya sudah lewat tengah malam
        // (lihat §QA-006 & test regresinya, DashboardTest).
        $to = Carbon::today()->endOfDay();

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

        // 1 query gabungan (COUNT + SUM sekaligus) menggantikan 2 query
        // terpisah yang sebelumnya ada di sini — pola yang sama persis dengan
        // LaporanController::penjualanData() (§5 temuan #5). toBase() supaya
        // turun ke query builder polos, bukan hydrate model Transaction yang
        // tidak dipakai sama sekali di sini.
        $summary = $base()->toBase()->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(grand_total), 0) as total')->first();
        $jumlahTransaksi = (int) $summary->jumlah;
        $omzetKotor = (int) $summary->total;

        // Retur yang TERJADI hari ini (apa pun tanggal jual transaksinya).
        $retur = SalesReturnReport::summary(Carbon::today(), Carbon::today()->endOfDay());

        return [
            'omzet' => $omzetKotor - $retur['total'], // BERSIH
            'omzet_kotor' => $omzetKotor,
            'retur' => $retur['total'],
            'jumlah_retur' => $retur['count'],
            'jumlah_transaksi' => $jumlahTransaksi,
            // Rata-rata = nilai keranjang saat penjualan (kotor), sama seperti Laporan Penjualan.
            'rata_rata' => $jumlahTransaksi > 0 ? (int) round($omzetKotor / $jumlahTransaksi) : 0,
            'stok_menipis_count' => Product::active()->lowStock()->count(),
        ];
    }

    /** Tren omzet harian (transaksi selesai) untuk grafik. */
    private function grafikPenjualan(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as tanggal, SUM(grand_total) as total')
            ->groupBy('tanggal')
            ->get();

        // Neto per hari: penjualan hari itu dikurangi retur yang terjadi hari itu. Bisa negatif bila
        // retur hari itu lebih besar dari penjualannya — sengaja TIDAK dipotong ke 0 (angka jujur).
        return $this->buildDailySeries($rows, $from, $to, SalesReturnReport::perDate($from, $to));
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
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('DATE(received_at) as tanggal, SUM(quantity_received * unit_price) as total')
            ->groupBy('tanggal')
            ->get();

        return $this->buildDailySeries($rows, $from, $to);
    }

    /** Isi tanggal yang kosong dengan 0, supaya grafik tidak bolong/miring. $subtract (opsional) dikurangkan per tanggal. */
    private function buildDailySeries(Collection $rows, Carbon $from, Carbon $to, ?Collection $subtract = null): array
    {
        $byDate = $rows->keyBy('tanggal');
        $minus = $subtract?->keyBy('tanggal');
        $labels = [];
        $values = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('d M');
            $values[] = (isset($byDate[$key]) ? (int) round((float) $byDate[$key]->total) : 0)
                - (isset($minus[$key]) ? (int) round((float) $minus[$key]->total) : 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Top 5 produk berdasar OMZET BERSIH (bukan qty mentah — qty tidak bisa dibandingkan antar produk
     * beda satuan). Retur dikurangkan SEBELUM dipotong 5 teratas karena retur bisa mengubah peringkat;
     * produk dengan omzet bersih nol/negatif tidak ikut.
     */
    private function produkTerlaris(Carbon $from, Carbon $to): Collection
    {
        $returPerProduk = SalesReturnReport::perProduct($from, $to);

        return TransactionDetail::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            // Dikelompokkan per product_id + nama (sama seperti LaporanController),
            // BUKAN per nama saja: dua produk BERBEDA yang kebetulan bernama sama
            // (mis. beda ukuran/kategori) sebelumnya tergabung jadi satu baris
            // dan membuat peringkat Top 5 berbeda dari yang tampil di Laporan.
            ->selectRaw('transaction_details.product_id, transaction_details.product_name, SUM(transaction_details.subtotal) as total_omzet, SUM(transaction_details.quantity) as total_qty')
            ->groupBy('transaction_details.product_id', 'transaction_details.product_name')
            ->get()
            ->map(function ($row) use ($returPerProduk) {
                $ret = $returPerProduk->get(SalesReturnReport::key($row));

                return (object) [
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name,
                    'total_omzet' => (int) $row->total_omzet - ($ret->omzet ?? 0),
                    'total_qty' => (float) $row->total_qty - ($ret->qty_unit ?? 0),
                ];
            })
            ->filter(fn ($row) => $row->total_omzet > 0)
            ->sortByDesc('total_omzet')
            ->take(5)
            ->values();
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