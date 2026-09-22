<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index tambahan untuk skala yang lebih besar nanti — belum kritis untuk
     * skala 1 toko sekarang (jumlah baris masih kecil), tapi murah untuk
     * ditambahkan lebih awal daripada nanti setelah data sudah besar (kapan
     * ALTER TABLE untuk tambah index jadi lambat & berpotensi mengunci tabel
     * yang sedang aktif dipakai produksi).
     *
     * - transactions(status, created_at): LaporanController (Penjualan &
     *   Laba/Rugi) dan DashboardController SELALU filter
     *   `where('status', 'completed')` DIGABUNG `whereBetween('created_at', ...)`
     *   di query yang sama. Index komposit ini lebih pas dibanding index
     *   `created_at` sendirian yang sudah ada dari migration awal — dengan
     *   index komposit, MySQL bisa memakai KEDUA kolom filter sekaligus dari
     *   1 index, bukan cuma salah satunya.
     * - purchase_orders(status): PurchaseOrderController::index() dan
     *   PurchaseReceiptController::index() sama-sama filter berat berdasarkan
     *   status (where/whereIn), dan kolom ini sebelumnya belum punya index
     *   sama sekali.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};