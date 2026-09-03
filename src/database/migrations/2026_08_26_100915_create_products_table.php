<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            // 'unit'   = satuan diskrit berjenjang (pcs, sachet, botol, dll) -> satuan
            //            dasar & satuan turunan (dus/renceng) dikelola di tabel product_units
            // 'weight' = timbang/curah (gram/kg) -> harga & konversi diartikan per kg
            $table->enum('tracking_mode', ['unit', 'weight'])->default('unit');

            $table->string('name');
            $table->string('sku')->unique();
            // Barcode TIDAK disimpan di level produk — barcode ada per level
            // satuan (lihat tabel product_units), karena kemasan pabrik yang
            // berbeda (dus/renceng/sachet) punya barcode fisik yang berbeda pula.

            // Stok SELALU dalam satuan dasar (base unit), desimal supaya
            // mendukung barang curah (kg/gram pecahan).
            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(5);

            // Harga pokok rata-rata tertimbang per satuan dasar, dihitung ulang
            // otomatis tiap ada barang masuk dari pembelian. Basis hitung laba/rugi.
            $table->unsignedBigInteger('average_cost')->default(0);

            // Produk curah yang boleh dijual dengan kuantitas pecahan bebas
            // (misal bawang per 0.35 kg), bukan cuma kelipatan satuan tetap.
            $table->boolean('allow_fractional_sale')->default(false);

            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
