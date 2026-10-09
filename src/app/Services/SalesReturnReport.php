<?php

namespace App\Services;

use App\Models\SalesReturnItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * SATU-SATUNYA sumber angka retur penjualan (retur pelanggan) untuk Laporan Penjualan,
 * Laba/Rugi, Stok (terlaris) dan Dashboard — supaya angka di layar, PDF, dan dashboard
 * tidak bisa saling berbeda.
 *
 * Aturan akuntansi (diputuskan di Tahap 1a/1b):
 *  - Penjualan bersih = penjualan (menurut TANGGAL JUAL) dikurangi retur (menurut TANGGAL RETUR).
 *    Karena itu transactions.status TIDAK diubah oleh retur; kalau diubah, retur terhitung dua kali.
 *  - Filter kasir & metode bayar di laporan mengikuti TRANSAKSI ASAL (bukan pemroses retur dan
 *    bukan metode pengembalian uang): "penjualan bersih kasir A" = penjualan A dikurangi retur
 *    atas penjualan A.
 *  - HPP dikurangi HANYA untuk barang 'resellable' (kembali ke stok, bisa dijual lagi). Barang
 *    'damaged' tidak mengurangi HPP, sehingga kerugiannya tampil di laba.
 *  - Qty terjual (laporan stok/dashboard) dikurangi retur apa pun kondisinya: barang yang
 *    dikembalikan pelanggan memang tidak jadi terjual.
 *  - Retur atas transaksi yang tidak berstatus 'completed' diabaikan, selaras dengan penjualannya
 *    yang juga tidak dihitung.
 */
class SalesReturnReport
{
    /**
     * Biaya (HPP) barang layak jual yang kembali ke stok. Basis biaya = unit_cost saat penjualan,
     * fallback average_cost SAAT INI untuk baris lama tanpa unit_cost — sama persis dengan
     * LaporanController::labaRugiData(). `condition` di-backtick: kata cadangan MySQL.
     */
    private const HPP_RESELLABLE = "COALESCE(SUM(CASE WHEN `sales_return_items`.`condition` = 'resellable'
        THEN COALESCE(`transaction_details`.`unit_cost`, `products`.`average_cost`)
             * `sales_return_items`.`quantity` * `sales_return_items`.`unit_conversion`
        ELSE 0 END), 0)";

    /** Query dasar baris retur yang terjadi dalam rentang (menurut tanggal retur). */
    public static function items(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return SalesReturnItem::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->join('transaction_details', 'transaction_details.id', '=', 'sales_return_items.transaction_detail_id')
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'sales_return_items.product_id')
            ->where('transactions.status', 'completed')
            ->whereBetween('sales_returns.created_at', [$from, $to]);
    }

    /**
     * Ringkasan retur dalam rentang.
     *
     * @return array{total: int, count: int, hpp: float, legacy: bool}
     *   total  = nilai uang yang dikembalikan; count = jumlah dokumen retur;
     *   hpp    = biaya barang layak jual yang kembali ke stok (pengurang HPP);
     *   legacy = ada baris retur yang biayanya memakai perkiraan average_cost (unit_cost kosong).
     */
    public static function summary(CarbonInterface $from, CarbonInterface $to, int|string|null $userId = null, ?string $paymentMethod = null): array
    {
        $scope = fn () => self::items($from, $to)
            ->when($userId !== null, fn ($q) => $q->where('transactions.user_id', $userId))
            ->when($paymentMethod !== null, fn ($q) => $q->where('transactions.payment_method', $paymentMethod));

        $row = $scope()->toBase()->selectRaw(
            'COALESCE(SUM(sales_return_items.refund_amount), 0) as total,
             COUNT(DISTINCT sales_returns.id) as jumlah,
             ' . self::HPP_RESELLABLE . ' as hpp'
        )->first();

        return [
            'total' => (int) $row->total,
            'count' => (int) $row->jumlah,
            'hpp' => (float) $row->hpp,
            'legacy' => $scope()->whereNull('transaction_details.unit_cost')->exists(),
        ];
    }

    /**
     * Retur per produk (dikelompokkan seperti penjualan: product_id + nama snapshot).
     *
     * @return Collection<string, object{product_id: int, product_name: string, qty: float, qty_unit: float, omzet: int, hpp: float}>
     *   dikunci "product_id|product_name" supaya mudah digabung dengan baris penjualan.
     *   qty = dalam satuan DASAR; qty_unit = dalam satuan jual transaksi (dipakai dashboard).
     */
    public static function perProduct(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return self::items($from, $to)->toBase()
            ->selectRaw(
                'sales_return_items.product_id,
                 sales_return_items.product_name,
                 SUM(sales_return_items.quantity * sales_return_items.unit_conversion) as qty,
                 SUM(sales_return_items.quantity) as qty_unit,
                 SUM(sales_return_items.refund_amount) as omzet,
                 ' . self::HPP_RESELLABLE . ' as hpp'
            )
            ->groupBy('sales_return_items.product_id', 'sales_return_items.product_name')
            ->get()
            ->mapWithKeys(fn ($r) => [self::key($r) => (object) [
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'qty' => (float) $r->qty,
                'qty_unit' => (float) $r->qty_unit,
                'omzet' => (int) $r->omzet,
                'hpp' => (float) $r->hpp,
            ]]);
    }

    /**
     * Nilai retur per tanggal retur (untuk grafik harian).
     *
     * @return Collection<int, object{tanggal: string, total: int|string}>
     */
    public static function perDate(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return self::items($from, $to)->toBase()
            ->selectRaw('DATE(sales_returns.created_at) as tanggal, SUM(sales_return_items.refund_amount) as total')
            ->groupBy('tanggal')
            ->get();
    }

    /** Kunci penggabungan baris penjualan & retur: product_id + nama snapshot. */
    public static function key(object $row): string
    {
        return $row->product_id . '|' . $row->product_name;
    }
}