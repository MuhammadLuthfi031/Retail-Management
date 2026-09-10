<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * PENTING (konkurensi): baris produk dikunci (`lockForUpdate`) SELAMA
     * transaksi ini berjalan. Tanpa ini, 2 proses yang mengubah stok produk
     * yang SAMA nyaris bersamaan (misal 2 staf Gudang input stok keluar di
     * waktu berdekatan, atau nanti beberapa kasir POS jual produk yang sama)
     * bisa sama-sama baca `stock` lama sebelum salah satunya selesai menulis
     * — yang commit belakangan akan diam-diam menimpa balik hasil yang lain
     * tanpa ada error apa pun ("lost update"). Dengan lock ini, proses kedua
     * otomatis menunggu proses pertama commit dulu, baru baca stok yang
     * sudah ter-update, sehingga selalu berbasis angka yang benar.
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
            // Kunci baris produk ini di database sampai transaksi selesai
            // (commit/rollback) — proses lain yang juga panggil record() untuk
            // product_id yang sama akan menunggu di titik ini, bukan jalan
            // paralel dengan basis data yang sama-sama basi.
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $stockBefore = (float) $locked->stock;

            if ($type === 'adjustment') {
                $isIncrease = $quantity >= 0;
                $absQuantity = abs($quantity);
            } else {
                $isIncrease = in_array($type, self::INCREASING_TYPES, true);
                $absQuantity = $quantity;
            }

            $stockAfter = $isIncrease ? $stockBefore + $absQuantity : $stockBefore - $absQuantity;

            // PENTING (race condition): validasi "qty tidak boleh melebihi stok" di
            // controller (StockController dsb) dibaca SEBELUM baris produk terkunci
            // di atas — kalau 2 request nyaris bersamaan (double-klik, atau nanti 2
            // kasir), keduanya bisa lolos validasi itu berbasis stok yang sama-sama
            // basi. Guard di sini jalan SETELAH lock didapat, jadi ini benteng
            // terakhir yang tidak bisa dilewati siapa pun — memastikan stok tidak
            // pernah minus apa pun jalur/urutan request yang masuk.
            if (! $isIncrease && $stockAfter < -0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => "Stok tidak cukup untuk operasi ini. Stok saat ini: {$stockBefore}, diminta: {$absQuantity}.",
                ]);
            }

            $movement = self::create([
                'product_id' => $locked->id,
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
                $updateData['average_cost'] = $locked->recalculateAverageCost($absQuantity, $unitCost);
            }

            $locked->update($updateData);

            // Sinkronkan balik ke instance $product yang dipegang caller (bukan
            // $locked yang cuma lokal di sini), supaya kode SETELAH pemanggilan
            // record() ini — termasuk caller yang loop banyak item untuk produk
            // yang sama, lihat PurchaseReceiptController::store() — selalu
            // melihat kondisi stok/harga pokok yang paling baru.
            $product->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });
    }
}