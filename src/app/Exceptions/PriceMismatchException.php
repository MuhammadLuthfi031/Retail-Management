<?php

namespace App\Exceptions;

use Exception;

/**
 * Dilempar oleh PosController::checkout() saat harga satuan yang dikirim
 * client (expected_price, diambil dari katalog/keranjang di layar kasir)
 * TIDAK sama dengan harga_jual satuan itu di database saat ini.
 *
 * Kenapa ini perlu ada di luar validasi biasa: bukan input yang salah
 * (client tidak berbuat curang), tapi data di layar kasir sudah usang —
 * Admin/Gudang mengubah harga SETELAH katalog dimuat ke browser kasir.
 * Bedanya dengan ValidationException (422 - "input Anda salah"): ini 409
 * ("kondisi berubah sejak Anda memuat halaman") supaya frontend bisa
 * membedakan & menampilkan pesan yang tepat, bukan menyalahkan kasir.
 *
 * Dilempar dari DALAM DB::transaction() SEBELUM ada satu pun baris ditulis
 * (Transaction/StockMovement) — jadi tidak butuh rollback eksplisit apa pun,
 * exception ini sendiri membatalkan transaksi DB via mekanisme normal
 * Laravel begitu keluar dari closure.
 */
class PriceMismatchException extends Exception
{
    /**
     * @param  array<int, array{product_id:int, product_name:string, unit_id:int, unit_name:string, expected_price:int, current_price:int}>  $mismatches
     */
    public function __construct(public readonly array $mismatches)
    {
        parent::__construct('Harga salah satu atau lebih produk sudah berubah sejak halaman ini dimuat.');
    }
}