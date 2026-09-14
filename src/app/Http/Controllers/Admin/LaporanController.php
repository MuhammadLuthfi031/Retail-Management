<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
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

    /** @return array{from: Carbon, to: Carbon, query: \Illuminate\Database\Eloquent\Builder, total_omzet: int, jumlah_transaksi: int, rata_rata: int, filters: array} */
    private function penjualanData(Request $request): array
    {
        [$from, $to] = $this->resolveDateRange($request, now()->startOfMonth(), now()->endOfDay());

        $base = fn () => Transaction::with('user')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->payment_method));

        $jumlahTransaksi = $base()->count();
        $totalOmzet = (int) $base()->sum('grand_total');
        $rataRata = $jumlahTransaksi > 0 ? (int) round($totalOmzet / $jumlahTransaksi) : 0;

        $paymentLabels = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];

        return [
            'from' => $from,
            'to' => $to,
            'query' => $base(),
            'total_omzet' => $totalOmzet,
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

        $totalOmzet = (int) $summary->total_omzet;
        $totalHpp = (int) round($summary->total_hpp);
        $totalLaba = $totalOmzet - $totalHpp;
        $margin = $totalOmzet > 0 ? round($totalLaba / $totalOmzet * 100, 1) : 0.0;

        $perProduk = $baseJoin()
            ->selectRaw(
                'transaction_details.product_id,
                 transaction_details.product_name,
                 SUM(transaction_details.quantity * transaction_details.unit_conversion) as qty_terjual,
                 SUM(transaction_details.subtotal) as omzet,
                 SUM(COALESCE(transaction_details.unit_cost, products.average_cost) * transaction_details.quantity * transaction_details.unit_conversion) as hpp'
            )
            ->groupBy('transaction_details.product_id', 'transaction_details.product_name')
            ->orderByDesc('omzet')
            ->limit(self::MAX_PDF_ROWS)
            ->get()
            ->map(function ($row) {
                $row->hpp = (int) round($row->hpp);
                $row->laba = (int) $row->omzet - $row->hpp;
                $row->margin = $row->omzet > 0 ? round($row->laba / $row->omzet * 100, 1) : 0.0;

                return $row;
            });

        // Ada baris transaksi lama (sebelum kolom unit_cost ada) yang cost
        // basis-nya dipakaikan average_cost SAAT INI sebagai perkiraan —
        // tampilkan catatan ini di UI supaya tidak disalahartikan sbg data
        // pasti akurat 100%.
        $adaDataLegacy = $baseJoin()->whereNull('transaction_details.unit_cost')->exists();

        return compact('from', 'to', 'totalOmzet', 'totalHpp', 'totalLaba', 'margin', 'perProduk', 'adaDataLegacy');
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

        $lowStockProducts = Product::active()->lowStock()->orderBy('name')->get();

        [$from, $to] = $this->resolveDateRange($request, now()->subDays(30)->startOfDay(), now()->endOfDay());

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
            ->orderByDesc('qty_terjual')
            ->limit(10)
            ->get();

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

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveDateRange(Request $request, Carbon $defaultFrom, Carbon $defaultTo): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : $defaultFrom;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : $defaultTo;

        return [$from, $to];
    }
}
