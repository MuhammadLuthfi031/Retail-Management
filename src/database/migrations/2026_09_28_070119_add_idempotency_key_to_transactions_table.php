<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QA-002: checkout POS harus idempotent terhadap retry jaringan.
     *
     * Client mengisi kolom ini dengan UUID v4 yang dibuat SEKALI per
     * percobaan checkout (saat modal pembayaran dibuka), lalu dikirim ulang
     * apa adanya di setiap klik "Proses Transaksi" dalam sesi modal yang
     * sama. PosController::checkout() memakai kolom ini untuk mengenali
     * pengulangan permintaan dan membalasnya dengan transaksi yang SUDAH
     * ADA — bukan membuat baris baru (stok tidak kepotong dobel).
     *
     * `unique()` adalah jaring pengaman terakhir di level database: walau
     * pengecekan di aplikasi terlewat karena race, INSERT kedua tetap
     * ditolak MySQL.
     *
     * `nullable()`: transaksi yang dibuat SEBELUM migrasi ini tidak punya
     * nilai. Tidak masalah — unique index MySQL hanya menolak DUPLIKAT nilai
     * non-NULL, banyak baris NULL diperbolehkan. Tidak perlu backfill.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};