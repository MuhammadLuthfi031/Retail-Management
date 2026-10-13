<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi Backup (QA-012)
|--------------------------------------------------------------------------
|
| Satu paket backup = satu file .zip berisi:
|   - database.sql      : dump database penuh (SATU-SATUNYA bagian yang dipakai restore)
|   - storage-public/   : foto produk, bukti pembayaran & bukti penerimaan barang
|   - arsip-data.xlsx   : salinan BACA-SAJA untuk arsip (tidak bisa dipakai restore)
|   - manifest.json     : metadata + checksum untuk verifikasi keutuhan saat restore
|
*/

return [

    /*
     | Folder tujuan backup. Di Docker diarahkan ke volume bind (/backups) yang
     | berada DI LUAR volume database, sehingga `docker compose down -v` tidak
     | ikut menghapus backup. Folder ini TIDAK boleh berada di dalam public/.
     */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /* Sumber file unggahan yang ikut di-backup, sekaligus tujuan saat restore. */
    'storage_source' => storage_path('app/public'),

    /* Backup lebih tua dari ini dihapus otomatis — HANYA setelah backup baru berhasil. */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /* Berapa pun umurnya, sekian backup terbaru tidak pernah dihapus. */
    'keep_min' => 3,

    /* Backup ditolak bila ruang disk kosong kurang dari ini (atau < 2x backup terbaru). */
    'min_free_mb' => (int) env('BACKUP_MIN_FREE_MB', 500),

    /*
     | Zona waktu untuk nama file, tampilan, dan jadwal backup. Sengaja eksplisit
     | (tidak mengikuti config('app.timezone')) supaya "23:00 WIB" tetap benar
     | apa pun keputusan QA-008 nanti.
     */
    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Jakarta'),
    'schedule_at' => env('BACKUP_SCHEDULE_AT', '23:00'),

    /* Backup sukses terakhir lebih tua dari ini dianggap TERLAMBAT (peringatan di UI). */
    'stale_after_hours' => 26,

    /* Penjadwal mencatat "detak" tiap menit; tanpa detak selama ini dianggap mati. */
    'scheduler_dead_after_minutes' => 5,

    /* Peringatan di UI bila ruang disk kosong kurang dari ini (jauh sebelum backup ditolak di min_free_mb). */
    'disk_warn_mb' => (int) env('BACKUP_DISK_WARN_MB', 2048),

    /* Batas waktu proses dump/restore (detik). */
    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

    /*
     | Pakai TLS saat dump/restore ke MySQL? Default TIDAK: database ada di jaringan internal
     | Docker (container `db`, tidak keluar dari komputer server), dan sertifikat bawaan MySQL
     | bersifat self-signed. Klien MariaDB versi baru (11.4+) menolak sertifikat semacam itu
     | ("TLS/SSL error: self-signed certificate in certificate chain") sehingga backup gagal.
     | Isi true HANYA bila database ada di server lain dan sertifikatnya sudah tepercaya.
     */
    'db_ssl' => (bool) env('BACKUP_DB_SSL', false),

    /* Biner klien. Di container `app` (Debian) keduanya disediakan paket klien MariaDB. */
    'dump_binary' => env('BACKUP_DUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysql'),

    /*
     |--------------------------------------------------------------------------
     | Isi arsip Excel
     |--------------------------------------------------------------------------
     | ALLOWLIST eksplisit: tabel baru (mis. sessions, cache, password_reset_tokens)
     | tidak pernah bocor ke Excel tanpa disengaja. `columns` = null berarti semua
     | kolom; untuk `users` kolom password & remember_token SENGAJA tidak ikut.
     */
    'excel_tables' => [
        'users' => ['label' => 'Pengguna', 'columns' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']],
        'categories' => ['label' => 'Kategori', 'columns' => null],
        'products' => ['label' => 'Produk', 'columns' => null],
        'product_units' => ['label' => 'Satuan Produk', 'columns' => null],
        'stock_movements' => ['label' => 'Pergerakan Stok', 'columns' => null],
        'transactions' => ['label' => 'Transaksi', 'columns' => null],
        'transaction_details' => ['label' => 'Detail Transaksi', 'columns' => null],
        'suppliers' => ['label' => 'Supplier', 'columns' => null],
        'purchase_orders' => ['label' => 'Purchase Order', 'columns' => null],
        'purchase_order_items' => ['label' => 'Item PO', 'columns' => null],
        'purchase_order_payments' => ['label' => 'Pembayaran PO', 'columns' => null],
        'purchase_order_receipts' => ['label' => 'Penerimaan PO', 'columns' => null],
        'sales_returns' => ['label' => 'Retur Penjualan', 'columns' => null],
        'sales_return_items' => ['label' => 'Item Retur Penjualan', 'columns' => null],
        'purchase_returns' => ['label' => 'Retur Pembelian', 'columns' => null],
        'purchase_return_items' => ['label' => 'Item Retur Pembelian', 'columns' => null],
    ],

];