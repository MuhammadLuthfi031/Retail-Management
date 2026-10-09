<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\SalesReturnReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modul Laporan (§7.3 spesifikasi): Penjualan, Laba/Rugi, Stok — masing-masing
 * bisa diekspor ke PDF. Setiap laporan punya method "*Data()" privat yang jadi
 * SATU-SATUNYA sumber query — dipakai bareng oleh method tampilan HTML & PDF
 * supaya angka yang ditampilkan di layar dan di file PDF dijamin selalu sama.
 *
 * RETUR PELANGGAN: semua angka penjualan di sini adalah angka BERSIH — penjualan (menurut
 * tanggal jual) dikurangi retur (menurut tanggal retur). Sumber angka retur hanya satu:
 * SalesReturnReport (aturan lengkapnya ada di docblock kelas itu).
 */
class LaporanController extends Controller
{
    private const MAX_PDF_ROWS = 500;

    // === PENJUALAN ===

    public function penjualan(Request $request): View
    {
        $data = $this->penjualanData($request);
        $data['transaksi'] = $data['query']->orderByDesc('created_at')->paginate(20)->withQueryString();
        $data['kasirList'] = User::whereIn('role', ['admin', 'kasir'])->orderBy('name')->get();
        unset($data['query']);

        return view('admin.laporan.penjualan', $data);
    }

    public function penjualanPdf(Request $request): Response
    {
        $data = $this->penjualanData($request);
        $data['transaksi'] = $data['query']->orderByDesc('created_at')->limit(self::MAX_PDF_ROWS)->get();
        $data['dibatasi'] = $data['jumlah_transaksi'] > self::MAX_PDF_ROWS;
        unset($data['query']);

        $pdf = Pdf::loadView('admin.laporan.pdf.penjualan', $data)->setPaper('a4', 'portrait');

        return $pdf->download('laporan-penjualan-' . $data['from']->format('Ymd') . '-' . $data['to']->format('Ymd') . '.pdf');
    }

    /** @return array{from: Carbon, to: Carbon, query: \Illuminate\Database\Eloquent\Builder, penjualan_kotor: int, total_retur: int, jumlah_retur: int, total_omzet: int, jumlah_transaksi: int, rata_rata: int, filters: array} */
    private function penjualanData(Request $request): array
    {
        [$from, $to] = $this->resolveDateRange($request, now()->startOfMonth(), now()->endOfDay());

        $base = fn () => Transaction::with('user')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->payment_method));

        // 1 query gabungan (COUNT + SUM sekaligus) menggantikan 2 query
        // terpisah yang sebelumnya ada di sini (masing-masing scan tabel yang
        // sama dengan filter yang sama persis). toBase() sengaja dipakai
        // supaya query ini turun ke query builder polos (hasilnya stdClass,
        // bukan model Transaction) — tanpa itu, eager-load with('user') dari
        // $base() ikut jalan sia-sia padahal cuma butuh 2 angka agregat, bukan
        // baris transaksi & relasinya.
        $summary = $base()->toBase()->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(grand_total), 0) as total')->first();
        $jumlahTransaksi = (int) $summary->jumlah;
        $penjualanKotor = (int) $summary->total;

        // Retur dalam rentang yang sama (menurut tanggal retur); kasir & metode bayar mengikuti transaksi asal.
        $retur = SalesReturnReport::summary(
            $from,
            $to,
            $request->filled('user_id') ? $request->user_id : null,
            $request->filled('payment_method') ? $request->payment_method : null,
        );
        $totalOmzet = $penjualanKotor - $retur['total'];
        // Rata-rata = nilai keranjang saat penjualan (kotor), bukan omzet bersih: retur atas penjualan
        // periode lain tidak boleh menyeret rata-rata (bahkan bisa membuatnya negatif).
        $rataRata = $jumlahTransaksi > 0 ? (int) round($penjualanKotor / $jumlahTransaksi) : 0;

        $paymentLabels = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];

        return [
            'from' => $from,
            'to' => $to,
            'query' => $base(),
            'penjualan_kotor' => $penjualanKotor,
            'total_retur' => $retur['total'],
            'jumlah_retur' => $retur['count'],
            'total_omzet' => $totalOmzet, // BERSIH = kotor - retur
            'jumlah_transaksi' => $jumlahTransaksi,
            'rata_rata' => $rataRata,
            'filters' => $request->only('user_id', 'payment_method'),
            'filterKasirName' => $request->filled('user_id') ? User::find($request->user_id)?->name : null,
            'filterPaymentLabel' => $paymentLabels[$request->payment_method] ?? null,
        ];
    }

    // === LABA/RUGI ===

    public function labaRugi(Request $request): View
    {
        return view('admin.laporan.laba-rugi', $this->labaRugiData($request));
    }

    public function labaRugiPdf(Request $request): Response
    {
        $data = $this->labaRugiData($request);
        $pdf = Pdf::loadView('admin.laporan.pdf.laba-rugi', $data)->setPaper('a4', 'portrait');

        return $pdf->download('laporan-laba-rugi-' . $data['from']->format('Ymd') . '-' . $data['to']->format('Ymd') . '.pdf');
    }

    private function labaRugiData(Request $request): array
    {
        [$from, $to] = $this->resolveDateRange($request, now()->startOfMonth(), now()->endOfDay());

        $baseJoin = fn () => TransactionDetail::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to]);

        $summary = $baseJoin()->selectRaw(
            'COALESCE(SUM(transaction_details.subtotal), 0) as total_omzet,
             COALESCE(SUM(COALESCE(transaction_details.unit_cost, products.average_cost) * transaction_details.quantity * transaction_details.unit_conversion), 0) as total_hpp'
        )->first();

        $retur = SalesReturnReport::summary($from, $to);

        $penjualanKotor = (int) $summary->total_omzet;
        $totalRetur = $retur['total'];
        // HPP bersih: HPP penjualan dikurangi biaya barang retur yang LAYAK JUAL (kembali ke stok).
        // Barang retur rusak tidak mengurangi HPP -> kerugiannya tampil di laba.
        $totalOmzet = $penjualanKotor - $totalRetur;
        $totalHpp = (int) round($summary->total_hpp - $retur['hpp']);
        $hppRetur = (int) round($retur['hpp']);
        $totalLaba = $totalOmzet - $totalHpp;
        $margin = $totalOmzet > 0 ? round($totalLaba / $totalOmzet * 100, 1) : 0.0;

        $returPerProduk = SalesReturnReport::perProduct($from, $to);

        $perProduk = $baseJoin()
            ->selectRaw(
                'transaction_details.product_id,
                 transaction_details.product_name,
                 SUM(transaction_details.quantity * transaction_details.unit_conversion) as qty_terjual,
                 SUM(transaction_details.subtotal) as omzet,
                 SUM(COALESCE(transaction_details.unit_cost, products.average_cost) * transaction_details.quantity * transaction_details.unit_conversion) as hpp'
            )
            ->groupBy('transaction_details.product_id', 'transaction_details.product_name')
            ->get()
            ->map(function ($row) use ($returPerProduk) {
                // pull(): baris retur yang cocok diambil (dan dikeluarkan) dari daftar retur;
                // sisanya (produk yang HANYA diretur di periode ini) ditambahkan di bawah.
                $ret = $returPerProduk->pull(SalesReturnReport::key($row));

                return $this->netProductRow(
                    $row->product_id, $row->product_name,
                    (float) $row->qty_terjual - ($ret->qty ?? 0),
                    (int) $row->omzet - ($ret->omzet ?? 0),
                    (float) $row->hpp - ($ret->hpp ?? 0),
                );
            })
            ->concat($returPerProduk->map(fn ($ret) => $this->netProductRow(
                // Produk dijual di periode lain, diretur di periode ini: tampil NEGATIF (memang mengurangi).
                $ret->product_id, $ret->product_name, -$ret->qty, -$ret->omzet, -$ret->hpp,
            ))->values())
            ->sortByDesc('omzet')
            ->take(self::MAX_PDF_ROWS)
            ->values();

        // Ada baris transaksi lama (sebelum kolom unit_cost ada) yang cost
        // basis-nya dipakaikan average_cost SAAT INI sebagai perkiraan —
        // tampilkan catatan ini di UI supaya tidak disalahartikan sbg data
        // pasti akurat 100%.
        $adaDataLegacy = $baseJoin()->whereNull('transaction_details.unit_cost')->exists() || $retur['legacy'];

        return compact(
            'from', 'to', 'penjualanKotor', 'totalRetur', 'hppRetur', 'totalOmzet', 'totalHpp', 'totalLaba',
            'margin', 'perProduk', 'adaDataLegacy'
        ) + ['jumlahRetur' => $retur['count']];
    }

    /** Satu baris laporan per-produk dalam angka BERSIH (penjualan dikurangi retur). */
    private function netProductRow(int $productId, string $name, float $qty, int $omzet, float $hpp): object
    {
        $hppInt = (int) round($hpp);
        $laba = $omzet - $hppInt;

        return (object) [
            'product_id' => $productId,
            'product_name' => $name,
            'qty_terjual' => $qty,
            'omzet' => $omzet,
            'hpp' => $hppInt,
            'laba' => $laba,
            'margin' => $omzet > 0 ? round($laba / $omzet * 100, 1) : 0.0,
        ];
    }

    // === STOK ===

    public function stok(Request $request): View
    {
        $data = $this->stokData($request);
        $data['produk'] = $data['produkQuery']->paginate(15)->withQueryString();
        $data['categories'] = Category::orderBy('name')->get();
        unset($data['produkQuery']);

        return view('admin.laporan.stok', $data);
    }

    public function stokPdf(Request $request): Response
    {
        $data = $this->stokData($request);
        $data['produk'] = $data['produkQuery']->limit(self::MAX_PDF_ROWS)->get();
        $data['dibatasi'] = $data['jumlahProdukSesuaiFilter'] > self::MAX_PDF_ROWS;
        unset($data['produkQuery']);

        $pdf = Pdf::loadView('admin.laporan.pdf.stok', $data)->setPaper('a4', 'landscape');

        return $pdf->download('laporan-stok-' . now()->format('Ymd') . '.pdf');
    }

    private function stokData(Request $request): array
    {
        $filtered = Product::with(['category', 'units'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->orderBy('name');

        // Skala toko single-branch: jumlah SKU realistis untuk di-load penuh
        // demi hitung total nilai inventori (pola yang sama dipakai
        // PurchaseOrderController::productsForForm()).
        $semuaSesuaiFilter = (clone $filtered)->get();
        $totalNilaiInventori = (int) $semuaSesuaiFilter->sum(fn ($p) => $p->stock * $p->average_cost);

        // with('units'): formatStock() di view "Stok Menipis" butuh ini —
        // sama persis polanya dengan DashboardController::stokMenipis() yang
        // baru kelewat kemarin. Query "produk stok menipis" ad-hoc semacam
        // ini gampang kelewat karena bukan bagian dari $filtered/produkQuery
        // utama yang sudah eager-load. Sudah saya pastikan tidak ada lagi
        // pemakaian lowStock() lain di seluruh controller yang bolong serupa.
        $lowStockProducts = Product::with('units')->active()->lowStock()->orderBy('name')->get();

        [$from, $to] = $this->resolveDateRange($request, now()->subDays(30)->startOfDay(), now()->endOfDay());

        // Qty & omzet BERSIH: dikurangi retur pelanggan (apa pun kondisinya, barang itu tidak jadi terjual).
        // Dikelompokkan sebelum dipotong 10 teratas, karena retur bisa mengubah peringkat.
        $returPerProduk = SalesReturnReport::perProduct($from, $to);

        $terlaris = TransactionDetail::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->selectRaw(
                'transaction_details.product_id,
                 transaction_details.product_name,
                 SUM(transaction_details.quantity * transaction_details.unit_conversion) as qty_terjual,
                 SUM(transaction_details.subtotal) as omzet'
            )
            ->groupBy('transaction_details.product_id', 'transaction_details.product_name')
            ->get()
            ->map(function ($row) use ($returPerProduk) {
                $ret = $returPerProduk->get(SalesReturnReport::key($row));

                return (object) [
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name,
                    'qty_terjual' => (float) $row->qty_terjual - ($ret->qty ?? 0),
                    'omzet' => (int) $row->omzet - ($ret->omzet ?? 0),
                ];
            })
            ->filter(fn ($row) => $row->qty_terjual > 0) // terjual bersih nol/negatif bukan "terlaris"
            ->sortByDesc('qty_terjual')
            ->take(10)
            ->values();

        return [
            'produkQuery' => $filtered,
            'jumlahProdukSesuaiFilter' => $semuaSesuaiFilter->count(),
            'totalNilaiInventori' => $totalNilaiInventori,
            'lowStockProducts' => $lowStockProducts,
            'terlaris' => $terlaris,
            'from' => $from,
            'to' => $to,
            'filterCategoryName' => $request->filled('category_id') ? Category::find($request->category_id)?->name : null,
        ];
    }

    // === Helper ===

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * Titik tunggal parsing rentang tanggal untuk semua method Laporan
     * (penjualan/labaRugi/stok x HTML+PDF = 6 titik masuk). Divalidasi DI SINI
     * — bukan di tiap method publik — supaya 1 perbaikan menutup semuanya
     * sekaligus (§QA-003). Sebelumnya Carbon::parse() dipanggil langsung ke
     * input mentah: string yang tidak bisa di-parse melempar
     * InvalidFormatException yang tidak ditangkap -> HTTP 500 generik, bukan
     * pesan validasi yang jelas.
     */
    private function resolveDateRange(Request $request, Carbon $defaultFrom, Carbon $defaultTo): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : $defaultFrom;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : $defaultTo;

        return [$from, $to];
    }
}