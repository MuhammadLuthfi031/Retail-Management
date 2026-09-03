<?php

use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Gudang\CategoryController;
use App\Http\Controllers\Gudang\ProductController;
use App\Http\Controllers\Gudang\PurchaseReceiptController;
use App\Http\Controllers\Gudang\StockController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // === KASIR ===
    Route::middleware('role:admin,kasir')->prefix('kasir')->name('kasir.')->group(function () {
        Route::get('/pos', fn () => view('kasir.pos'))->name('pos');
        Route::get('/produk', fn () => view('kasir.produk'))->name('produk');
    });

    // === GUDANG ===
    Route::middleware('role:admin,gudang')->prefix('gudang')->name('gudang.')->group(function () {
        Route::resource('kategori', CategoryController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['kategori' => 'category']);

        Route::resource('produk', ProductController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy'])
            ->parameters(['produk' => 'product']);

        // Stok (keluar/mutasi/opname/riwayat) — lihat § 5.4 spesifikasi.
        // Sisi "masuk" sudah tercover dari alur Konfirmasi Penerimaan Pembelian.
        Route::prefix('stok')->name('stok.')->group(function () {
            Route::get('/', [StockController::class, 'index'])->name('index');
            Route::get('/{product}', [StockController::class, 'show'])->name('show');
            Route::post('/{product}/keluar', [StockController::class, 'storeOut'])->name('keluar');
            Route::post('/{product}/mutasi', [StockController::class, 'storeMutation'])->name('mutasi');
            Route::post('/{product}/opname', [StockController::class, 'storeAdjustment'])->name('opname');
        });

        // Konfirmasi penerimaan barang PO (sisi Gudang, lihat § 5.3 & § 6.3 spesifikasi)
        Route::prefix('pembelian')->name('pembelian.')->group(function () {
            Route::get('/', [PurchaseReceiptController::class, 'index'])->name('index');
            Route::get('/{pembelian}', [PurchaseReceiptController::class, 'show'])->name('show');
            Route::post('/{pembelian}', [PurchaseReceiptController::class, 'store'])->name('store');
        });
    });

    // === ADMIN ===
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', fn () => view('admin.dashboard'))->name('dashboard');
        Route::get('/users', fn () => view('admin.users'))->name('users');
        Route::get('/laporan', fn () => view('admin.laporan'))->name('laporan');

        Route::resource('supplier', SupplierController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Purchase Order (dikelola Admin, lihat § 6.2 spesifikasi)
        Route::resource('pembelian', PurchaseOrderController::class)
            ->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::put('/pembelian/{pembelian}/tandai-dipesan', [PurchaseOrderController::class, 'markOrdered'])
            ->name('pembelian.mark-ordered');
        Route::put('/pembelian/{pembelian}/batalkan', [PurchaseOrderController::class, 'cancel'])
            ->name('pembelian.cancel');
        Route::put('/pembelian/{pembelian}/status-bayar', [PurchaseOrderController::class, 'updatePaymentStatus'])
            ->name('pembelian.payment-status');
    });
});

require __DIR__.'/auth.php';
