<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // yang input (gudang/admin)

            $table->enum('type', ['in', 'out', 'mutation', 'adjustment', 'sale']);
            // in         = stok masuk (pembelian/restock)
            // out        = stok keluar manual (rusak, hilang, dll)
            // mutation   = mutasi/transfer (misal antar gudang, untuk multi-cabang nanti)
            // adjustment = koreksi stok (stok opname)
            // sale       = otomatis terpotong dari transaksi kasir

            $table->decimal('quantity', 12, 3); // selalu positif, arah ditentukan oleh 'type'

            // Harga pokok per satuan dasar SAAT movement ini terjadi (hanya diisi untuk
            // stok masuk dari pembelian). Jejak audit + basis hitung average_cost produk.
            $table->unsignedBigInteger('unit_cost')->nullable();

            $table->decimal('stock_before', 12, 3);
            $table->decimal('stock_after', 12, 3);
            $table->string('reference')->nullable(); // no. transaksi / no. faktur pembelian
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
