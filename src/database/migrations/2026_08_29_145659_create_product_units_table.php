<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('unit_name'); // "dus", "renceng", "sachet", "kg", "gram", dst
            $table->decimal('conversion_to_base', 12, 3);
            // Berapa satuan dasar (terkecil) yang terkandung dalam 1 unit ini.
            // Contoh: dus=288 (kalau base=sachet), renceng=12, sachet=1 (base unit selalu 1)
            $table->string('barcode')->nullable()->unique();
            // Barcode BISA berbeda per level kemasan (barcode dus ≠ barcode sachet dari pabrik)
            $table->unsignedBigInteger('selling_price')->nullable();
            // Harga jual di level satuan ini. Null berarti satuan ini tidak dijual langsung
            // (misal "dus" cuma dipakai sebagai satuan beli, tidak pernah dijual utuh ke pembeli)
            $table->boolean('is_base_unit')->default(false);
            // Penanda satuan dasar/terkecil untuk tracking stok (HARUS ada tepat 1 per produk)
            $table->boolean('is_purchase_unit')->default(false);
            // Penanda satuan default yang dipakai saat belanja/restock dari supplier
            $table->integer('sort_order')->default(0);
            // Urutan tampil di dropdown (biasanya dari terbesar ke terkecil)
            $table->timestamps();

            $table->unique(['product_id', 'unit_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};