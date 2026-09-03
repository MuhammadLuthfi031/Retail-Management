<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name'); // snapshot nama produk saat transaksi

            // Snapshot satuan yang dipilih kasir saat transaksi (misal "renceng", "kg", "gram").
            // Disimpan sebagai teks (bukan FK) supaya riwayat transaksi lama tetap valid
            // meskipun satuan produk diubah/dihapus di kemudian hari.
            $table->string('unit_name')->nullable();

            // Snapshot rasio konversi ke base unit SAAT transaksi ini terjadi.
            // Dipakai untuk menghitung pengurangan stok base unit yang akurat.
            $table->decimal('unit_conversion', 12, 3)->default(1);

            $table->unsignedBigInteger('price'); // snapshot harga jual saat transaksi
            $table->unsignedBigInteger('discount_amount')->default(0); // diskon per item baris
            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_details');
    }
};
