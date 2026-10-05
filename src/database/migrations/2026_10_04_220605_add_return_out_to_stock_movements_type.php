<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // return_out = barang dikembalikan ke supplier (retur pembelian). Stok BERKURANG.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['in', 'out', 'mutation', 'adjustment', 'sale', 'return_in', 'return_out'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['in', 'out', 'mutation', 'adjustment', 'sale', 'return_in'])->change();
        });
    }
};