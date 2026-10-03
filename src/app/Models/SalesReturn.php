<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Retur penjualan (retur pelanggan) — header satu sesi retur atas satu transaksi.
 *
 * PENTING: retur TIDAK mengubah transactions.status (tetap 'completed'). Laporan
 * menghitung penjualan bersih = penjualan (menurut tanggal jual) dikurangi retur
 * (menurut tanggal retur). Kalau status transaksi ikut diubah ke 'refunded',
 * retur akan terhitung dua kali (transaksi hilang dari penjualan DAN nilainya
 * dikurangkan lagi sebagai retur).
 */
class SalesReturn extends Model
{
    protected $fillable = [
        'return_number',
        'idempotency_key',
        'transaction_id',
        'user_id',
        'refund_method',
        'total_refund',
        'reason',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    /**
     * Nomor retur berurutan per hari (RTN-YYYYMMDD-0001). Pola identik dengan
     * Transaction::generateInvoiceNumber(): WAJIB dipanggil dari dalam
     * DB::transaction() supaya lockForUpdate() berefek.
     */
    public static function generateReturnNumber(): string
    {
        $prefix = 'RTN-' . now()->format('Ymd');
        $last = self::where('return_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $sequence = $last ? ((int) substr($last->return_number, -4)) + 1 : 1;

        return $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan dengan return_number yang DIJAMIN unik. Hanya bentrok pada
     * return_number yang di-retry; bentrok idempotency_key harus naik ke
     * pemanggil (SalesReturnService menanganinya sebagai replay).
     */
    public static function createWithUniqueNumber(array $attributes, int $maxAttempts = 3): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return self::create([...$attributes, 'return_number' => self::generateReturnNumber()]);
            } catch (QueryException $e) {
                $isNumberClash = $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'return_number');

                if (! $isNumberClash || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat return_number yang unik setelah beberapa kali percobaan.');
    }
}