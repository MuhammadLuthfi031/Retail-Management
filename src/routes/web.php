<?php

use App\Http\Controllers\Admin\LaporanController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Gudang\CategoryController;
use App\Http\Controllers\Gudang\ProductController;
use App\Http\Controllers\Gudang\PurchaseReceiptController;
use App\Http\Controllers\Gudang\StockController;
use App\Http\Controllers\Kasir\PosController;
use App\Http\Controllers\Kasir\RiwayatController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Root URL: tidak ada halaman publik/marketing untuk sistem internal ini.
// Kalau sudah login, langsung ke "halaman utama" sesuai role (satu sumber
// kebenaran: User::homeRouteName()). Kalau belum, ke halaman login.
// Middleware 'no-cache' WAJIB di sini: response-nya bergantung status auth
// (dinamis) — tanpa ini, browser bisa nyimpen cache dari kunjungan
// sebelumnya (mis. form login) dan tetap nampilin itu meski status login
// user sudah berubah. Lihat PreventBackHistoryCache.php untuk kasus serupa.
Route::get('/', function () {
    return Auth::check()
        ? redirect()->route(Auth::user()->homeRouteName())
        : redirect()->route('login');
})->middleware('no-cache');

Route::middleware(['auth', 'no-cache'])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // === KASIR ===
    Route::middleware('role:admin,kasir')->prefix('kasir')->name('kasir.')->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->name('pos');
        Route::get('/pos/cari', [PosController::class, 'search'])->name('pos.cari');
        Route::get('/pos/katalog', [PosController::class, 'katalog'])->name('pos.katalog');
        Route::get('/pos/barcode/{code}', [PosController::class, 'lookup'])->name('pos.barcode');
        Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::get('/riwayat', [RiwayatController::class, 'index'])->name('riwayat');
        Route::get('/riwayat/{transaction}/struk', [RiwayatController::class, 'struk'])->name('riwayat.struk');
        Route::get('/produk', [PosController::class, 'produk'])->name('produk');
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
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Laporan (§7.3 spesifikasi) — 3 laporan + export PDF masing-masing.
        Route::prefix('laporan')->name('laporan.')->group(function () {
            Route::get('/penjualan', [LaporanController::class, 'penjualan'])->name('penjualan');
            Route::get('/penjualan/pdf', [LaporanController::class, 'penjualanPdf'])->name('penjualan.pdf');
            Route::get('/laba-rugi', [LaporanController::class, 'labaRugi'])->name('laba-rugi');
            Route::get('/laba-rugi/pdf', [LaporanController::class, 'labaRugiPdf'])->name('laba-rugi.pdf');
            Route::get('/stok', [LaporanController::class, 'stok'])->name('stok');
            Route::get('/stok/pdf', [LaporanController::class, 'stokPdf'])->name('stok.pdf');
        });

        // Manajemen User/Karyawan (§7.2 spesifikasi) — lihat UserController
        // untuk alasan kenapa sengaja tidak ada route hapus permanen.
        Route::resource('users', UserController::class)->only(['index', 'store', 'update']);
        Route::put('/users/{user}/toggle-status', [UserController::class, 'toggleActive'])->name('users.toggle-status');
        Route::put('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

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