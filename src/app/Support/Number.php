<?php

namespace App\Support;

class Number
{
    /**
     * Format angka desimal untuk tampilan/pengisian ulang di UI: batasi ke
     * sejumlah desimal tertentu lalu buang nol (dan titik) di belakang yang
     * tidak perlu — misal 1.000 -> "1", 1.500 -> "1.5", 0.333 -> "0.333".
     *
     * Sebelumnya pola `rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.')`
     * ini ditulis ulang manual di banyak tempat (Product, StockController,
     * beberapa view Produk & Pembelian) — dipusatkan di sini supaya kalau
     * suatu saat aturan formatnya perlu diubah, cukup di satu tempat, dan
     * supaya tidak ada risiko satu tempat lupa ikut diperbarui sementara
     * yang lain sudah, yang bisa bikin tampilan angka jadi tidak konsisten.
     */
    public static function trim(float|int|string $value, int $decimals = 3): string
    {
        return rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    }
}
