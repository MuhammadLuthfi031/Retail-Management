<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // return_in = barang layak jual yang dikembalikan pelanggan (retur penjualan).
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['in', 'out', 'mutation', 'adjustment', 'sale', 'return_in'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('type', ['in', 'out', 'mutation', 'adjustment', 'sale'])->change();
        });
    }
};