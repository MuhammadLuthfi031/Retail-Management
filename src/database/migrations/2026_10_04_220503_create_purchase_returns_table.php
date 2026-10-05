<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retur ke supplier. Satu baris = satu sesi retur atas SATU PO. PO aslinya
        // TIDAK diubah (total_amount & quantity_received tetap sebagai riwayat
        // apa yang dipesan/diterima); retur adalah dokumen terpisah.
        //
        // restrictOnDelete ke PO & users: jejak audit keuangan/stok, tidak boleh
        // ikut hilang diam-diam (lihat QA-013).
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();            // RTP-YYYYMMDD-0001
            $table->uuid('idempotency_key')->unique();            // anti dobel-klik
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // pembuat (Gudang/Admin)
            $table->string('reason', 255);
            $table->unsignedBigInteger('total_value');            // nilai menurut harga beli di PO; HANYA terlihat Admin

            // Penyelesaian oleh Admin: pending -> settled. Jenisnya baru dipilih saat
            // diselesaikan (Gudang tidak tahu kesepakatan dengan supplier). 'replacement'
            // disiapkan untuk Tahap 2b (aksi "Terima pengganti").
            $table->enum('status', ['pending', 'settled'])->default('pending');
            $table->enum('settlement_type', ['refund', 'credit', 'replacement'])->nullable();
            $table->string('settlement_note', 255)->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Snapshot satuan yang dipilih saat retur (boleh beda dari satuan beli:
            // beli 5 dus, yang rusak 3 sachet).
            $table->string('product_name');
            $table->string('unit_name');
            $table->decimal('unit_conversion', 12, 3);
            $table->decimal('quantity', 12, 3);       // dalam satuan yang dipilih
            $table->decimal('quantity_base', 14, 3);  // dalam satuan dasar — dasar batas & pengurangan stok
            $table->unsignedBigInteger('value');      // nilai baris menurut harga beli di PO
            $table->timestamps();

            $table->index('purchase_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};