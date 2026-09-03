<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'type',
        'quantity',
        'unit_cost',
        'stock_before',
        'stock_after',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
        ];
    }

    public const INCREASING_TYPES = ['in', 'adjustment'];
    public const DECREASING_TYPES = ['out', 'mutation', 'sale'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Buat record pergerakan stok sekaligus update stok produk secara atomik.
     * Semua kuantitas WAJIB dalam satuan dasar (base unit) produk.
     *
     * Untuk type 'in', 'out', 'mutation', 'sale': $quantity WAJIB positif —
     * arah pergerakan (naik/turun) sepenuhnya ditentukan oleh $type.
     *
     * Untuk type 'adjustment' (hasil stok opname): $quantity BOLEH negatif
     * kalau stok fisik ternyata LEBIH SEDIKIT dari catatan sistem. Arah
     * ditentukan dari tanda (+/-) nilainya, tapi yang disimpan di kolom
     * `quantity` selalu nilai absolut (selaras dengan komentar di migration:
     * "selalu positif, arah ditentukan oleh type").
     *
     * @param  int|null  $unitCost  Harga pokok per satuan dasar SAAT stok masuk ini terjadi.
     *                              Hanya isi untuk type 'in' yang berasal dari pembelian —
     *                              akan otomatis memicu perhitungan ulang average_cost produk.
     */
    public static function record(
        Product $product,
        string $type,
        float $quantity,
        int $userId,
        ?string $reference = null,
        ?string $note = null,
        ?int $unitCost = null
    ): self {
        return DB::transaction(function () use ($product, $type, $quantity, $userId, $reference, $note, $unitCost) {
            $stockBefore = (float) $product->stock;

            if ($type === 'adjustment') {
                $isIncrease = $quantity >= 0;
                $absQuantity = abs($quantity);
            } else {
                $isIncrease = in_array($type, self::INCREASING_TYPES, true);
                $absQuantity = $quantity;
            }

            $stockAfter = $isIncrease ? $stockBefore + $absQuantity : $stockBefore - $absQuantity;

            $movement = self::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'type' => $type,
                'quantity' => $absQuantity,
                'unit_cost' => $unitCost,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference' => $reference,
                'note' => $note,
            ]);

            $updateData = ['stock' => $stockAfter];

            // Hitung ulang harga pokok rata-rata HANYA kalau ini stok masuk dengan info harga
            if ($type === 'in' && $unitCost !== null) {
                $updateData['average_cost'] = $product->recalculateAverageCost($absQuantity, $unitCost);
            }

            $product->update($updateData);

            return $movement;
        });
    }
}
