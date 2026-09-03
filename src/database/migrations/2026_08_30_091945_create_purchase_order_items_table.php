<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_unit_id')->constrained();
            // Satuan pembelian yang dipakai untuk item ini (dus/karung/kompan)

            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_received', 12, 3)->default(0);
            // Mendukung penerimaan sebagian: quantity_received bisa < quantity_ordered

            $table->unsignedBigInteger('unit_price'); // harga beli per satuan pembelian ini
            $table->unsignedBigInteger('subtotal');

            $table->foreignId('received_by')->nullable()->constrained('users');
            // Gudang yang konfirmasi penerimaan (nullable karena belum tentu sudah diterima)
            $table->timestamp('received_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};