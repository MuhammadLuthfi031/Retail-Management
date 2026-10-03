<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header retur penjualan (retur pelanggan). Satu baris = satu sesi retur
        // oleh Admin atas SATU transaksi; boleh berkali-kali per transaksi selama
        // total qty yang diretur tidak melebihi qty terjual.
        //
        // Sengaja restrictOnDelete (bukan cascade) ke transactions & users: ini
        // jejak audit keuangan — tidak boleh ikut hilang diam-diam (lihat QA-013).
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();           // RTN-YYYYMMDD-0001
            $table->uuid('idempotency_key')->unique();           // anti dobel-klik: lihat SalesReturnController
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // admin pemroses
            $table->enum('refund_method', ['cash', 'debit', 'qris', 'transfer']);
            $table->unsignedBigInteger('total_refund');
            $table->string('reason', 255);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_detail_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Snapshot dari baris transaksi asal (riwayat tetap akurat walau produk/satuan berubah).
            $table->string('product_name');
            $table->string('unit_name')->nullable();
            $table->decimal('unit_conversion', 12, 3)->default(1);

            // Dalam SATUAN JUAL yang dipakai saat transaksi (bukan satuan dasar).
            $table->decimal('quantity', 12, 3);

            // resellable = layak jual, kembali ke stok. damaged = rusak, TIDAK masuk stok.
            $table->enum('condition', ['resellable', 'damaged']);

            $table->unsignedBigInteger('refund_amount');
            $table->timestamps();

            $table->index('transaction_detail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};