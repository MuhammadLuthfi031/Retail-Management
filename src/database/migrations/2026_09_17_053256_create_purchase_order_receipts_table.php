<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat SETIAP sesi konfirmasi penerimaan barang (1 baris = 1 kali
        // klik "Konfirmasi Penerimaan", bisa mencakup beberapa item PO
        // sekaligus), lengkap dengan foto bukti fisik barang datang. Terpisah
        // dari purchase_order_items.received_at/received_by yang tetap jalan
        // seperti biasa (itu "sentuhan terakhir" per-item, bukan per-sesi).
        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('proof_path'); // foto bukti fisik barang datang (nota/packing/dll)
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipts');
    }
};