<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            // Snapshot average_cost produk (per satuan dasar) PERSIS SAAT baris
            // ini terjual — basis Laporan Laba/Rugi (§7.3 spesifikasi). Nullable
            // karena transaksi yang dibuat SEBELUM migrasi ini tidak punya data
            // ini; laporan akan fallback ke average_cost produk saat ini untuk
            // baris lama tsb (lihat LaporanController), dengan catatan di UI
            // bahwa itu perkiraan, bukan snapshot asli.
            $table->unsignedBigInteger('unit_cost')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
