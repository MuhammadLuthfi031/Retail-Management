<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Retur ke supplier (retur pembelian) — header satu sesi retur atas satu PO.
 *
 * PENTING: retur TIDAK mengubah purchase_orders (status, total_amount) maupun
 * purchase_order_items.quantity_received — itu catatan apa yang dipesan/diterima.
 * Qty yang sudah diretur dilacak di purchase_return_items.
 */
class PurchaseReturn extends Model
{
    protected $fillable = [
        'return_number',
        'idempotency_key',
        'purchase_order_id',
        'user_id',
        'reason',
        'total_value',
        'status',
        'settlement_type',
        'settlement_note',
        'settled_by',
        'settled_at',
    ];

    protected function casts(): array
    {
        return ['settled_at' => 'datetime'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /** Nomor berurutan per hari (RTP-YYYYMMDD-0001). WAJIB dipanggil dari dalam DB::transaction(). */
    public static function generateReturnNumber(): string
    {
        $prefix = 'RTP-' . now()->format('Ymd');
        $last = self::where('return_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $sequence = $last ? ((int) substr($last->return_number, -4)) + 1 : 1;

        return $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** Simpan dengan return_number unik; hanya bentrok return_number yang di-retry. */
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