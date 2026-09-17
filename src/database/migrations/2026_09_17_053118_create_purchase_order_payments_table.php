<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat SETIAP kali status pembayaran PO berubah, lengkap dengan
        // bukti transfer/pembayarannya — bukan cuma 1 kolom foto yang ditimpa
        // di tabel purchase_orders, supaya bukti lama tidak hilang kalau
        // status berubah lagi (mis. dari "sebagian" ke "lunas").
        Schema::create('purchase_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->enum('from_status', ['unpaid', 'partial', 'paid']);
            $table->enum('to_status', ['unpaid', 'partial', 'paid']);
            $table->string('proof_path'); // foto bukti transfer/pembayaran
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_payments');
    }
};