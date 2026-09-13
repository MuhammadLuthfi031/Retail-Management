<?php

namespace App\Models;

use App\Models\TransactionDetail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'user_id',
        'total_amount',
        'discount_amount',
        'grand_total',
        'paid_amount',
        'change_amount',
        'payment_method',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    /**
     * PENTING (race condition): kunci baris invoice TERAKHIR hari ini (kalau
     * sudah ada) dengan `lockForUpdate()` — proses checkout lain yang
     * menghitung invoice_number untuk tanggal yang sama harus menunggu
     * transaksi ini commit dulu, bukan sama-sama baca angka basi yang sama.
     * Pola ini konsisten dengan lock di StockMovement::record().
     *
     * Kalau method ini dipanggil dari luar transaksi DB aktif, lockForUpdate()
     * di sini tidak berefek (tidak ada transaksi utk dikunci) — makanya method
     * ini HARUS selalu dipanggil dari dalam DB::transaction(), lihat
     * createWithUniqueInvoice() di bawah yang jadi satu-satunya pemanggil.
     *
     * CATATAN: lock ini tidak menutup SATU celah — transaksi PERTAMA di hari
     * yang sama (belum ada baris untuk dikunci sama sekali, jadi 2 checkout
     * yang nyaris bersamaan bisa sama-sama hitung sequence=1). Lapis kedua
     * untuk kasus itu ada di createWithUniqueInvoice() lewat retry kalau
     * constraint unique() di DB menolak.
     */
    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ymd');
        $lastToday = self::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $sequence = $lastToday
            ? ((int) substr($lastToday->invoice_number, -4)) + 1
            : 1;

        return $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan Transaction baru dengan invoice_number yang DIJAMIN unik, walau
     * ada 2 checkout nyaris bersamaan (mis. 2 device kasir aktif berbarengan
     * saat jam ramai). Dipakai PosController::checkout() sebagai pengganti
     * `self::create([...'invoice_number' => generateInvoiceNumber()...])`
     * langsung.
     *
     * Kenapa perlu lapis retry di sini padahal generateInvoiceNumber() sudah
     * dikunci: lock di atas cuma bekerja kalau SUDAH ADA baris invoice hari
     * itu untuk dikunci. Untuk transaksi PERTAMA di suatu hari, 2 proses bisa
     * sama-sama lolos dengan sequence=1 lalu sama-sama coba INSERT — yang
     * kedua akan gagal karena constraint unique() di kolom invoice_number.
     * Daripada checkout itu gagal total dengan pesan error yang membingungkan
     * kasir, kita tangkap error itu spesifik lalu coba lagi dengan nomor baru.
     *
     * WAJIB dipanggil dari dalam DB::transaction() yang sudah berjalan (lihat
     * catatan di generateInvoiceNumber()) — PosController::checkout() sudah
     * memenuhi ini.
     */
    public static function createWithUniqueInvoice(array $attributes, int $maxAttempts = 3): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return self::create([
                    ...$attributes,
                    'invoice_number' => self::generateInvoiceNumber(),
                ]);
            } catch (QueryException $e) {
                $isIntegrityViolation = $e->getCode() === '23000';

                if (! $isIntegrityViolation || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Lanjut ke percobaan berikutnya dengan invoice_number baru.
            }
        }
    }
}