<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'supplier_id',
        'created_by',
        'order_date',
        'expected_date',
        'status',
        'payment_status',
        'total_amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(PurchaseOrderPayment::class)->latest();
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseOrderReceipt::class)->latest();
    }

    /**
     * Urutan (rank) status pembayaran — dipakai untuk memastikan status
     * pembayaran CUMA BISA MAJU (unpaid -> partial -> paid, atau langsung
     * unpaid -> paid), TIDAK PERNAH bisa dikembalikan mundur. Lihat
     * PurchaseOrderController::updatePaymentStatus() untuk pemakaiannya.
     */
    public const PAYMENT_STATUS_RANK = [
        'unpaid' => 0,
        'partial' => 1,
        'paid' => 2,
    ];

    public function canAdvancePaymentStatusTo(string $newStatus): bool
    {
        return self::PAYMENT_STATUS_RANK[$newStatus] > self::PAYMENT_STATUS_RANK[$this->payment_status];
    }

    /**
     * PENTING (race condition): kunci baris PO TERAKHIR hari ini (kalau sudah
     * ada) dengan `lockForUpdate()` — proses lain yang menghitung po_number
     * untuk tanggal yang sama harus menunggu transaksi ini commit dulu, bukan
     * sama-sama baca angka basi yang sama. Pola ini konsisten dengan
     * Transaction::generateInvoiceNumber() (lihat catatan lengkap di sana).
     *
     * CATATAN: lock ini tidak menutup SATU celah — PO PERTAMA di hari yang
     * sama (belum ada baris untuk dikunci sama sekali, jadi 2 proses yang
     * nyaris bersamaan bisa sama-sama hitung sequence=1). Lapis kedua untuk
     * kasus itu ada di createWithUniquePoNumber() lewat retry kalau
     * constraint unique() di DB menolak.
     *
     * HARUS selalu dipanggil dari dalam DB::transaction() yang sudah berjalan
     * — lihat createWithUniquePoNumber() di bawah yang jadi satu-satunya
     * pemanggil (dipanggil dari PurchaseOrderController::store()).
     */
    public static function generatePoNumber(): string
    {
        $prefix = 'PO-' . now()->format('Ymd');
        $lastToday = self::where('po_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $sequence = $lastToday ? ((int) substr($lastToday->po_number, -4)) + 1 : 1;

        return $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan PurchaseOrder baru dengan po_number yang DIJAMIN unik, walau ada
     * 2 admin (atau 2 tab / double-klik submit) yang buat PO nyaris bersamaan
     * di hari yang sama. Dipakai PurchaseOrderController::store() sebagai
     * pengganti `self::create([...'po_number' => generatePoNumber()...])`
     * langsung.
     *
     * Kenapa perlu lapis retry di sini padahal generatePoNumber() sudah
     * dikunci: sama seperti Transaction::createWithUniqueInvoice() — lock di
     * atas cuma bekerja kalau SUDAH ADA baris PO hari itu untuk dikunci.
     * Untuk PO PERTAMA di suatu hari, 2 proses bisa sama-sama lolos dengan
     * sequence=1 lalu sama-sama coba INSERT — yang kedua akan gagal karena
     * constraint unique() di kolom po_number. Daripada gagal total dengan
     * 500 error mentah, kita tangkap error itu spesifik lalu coba lagi
     * dengan nomor baru.
     *
     * WAJIB dipanggil dari dalam DB::transaction() yang sudah berjalan.
     */
    public static function createWithUniquePoNumber(array $attributes, int $maxAttempts = 3): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return self::create([
                    ...$attributes,
                    'po_number' => self::generatePoNumber(),
                ]);
            } catch (QueryException $e) {
                $isIntegrityViolation = $e->getCode() === '23000';

                if (! $isIntegrityViolation || $attempt === $maxAttempts) {
                    throw $e;
                }
                // Lanjut ke percobaan berikutnya dengan po_number baru.
            }
        }

        // Baris ini secara LOGIKA tidak akan pernah kesampaian — percobaan
        // terakhir ($attempt === $maxAttempts) di atas selalu throw kalau
        // masih gagal. Tetap ditulis eksplisit supaya PHP & static analyzer
        // tidak menganggap method ber-return-type `self` ini diam-diam bisa
        // menghasilkan null.
        throw new \RuntimeException('Gagal membuat nomor PO unik setelah beberapa kali percobaan.');
    }
}