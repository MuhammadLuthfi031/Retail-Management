<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'tracking_mode',
        'name',
        'sku',
        'stock',
        'min_stock',
        'average_cost',
        'allow_fractional_sale',
        'image',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_fractional_sale' => 'boolean',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
        ];
    }

    // === Relasi ===

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function units()
    {
        return $this->hasMany(ProductUnit::class)->orderBy('sort_order');
    }

    public function baseUnit()
    {
        return $this->hasOne(ProductUnit::class)->where('is_base_unit', true);
    }

    public function purchaseUnit()
    {
        return $this->hasOne(ProductUnit::class)->where('is_purchase_unit', true);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    // === Helper ===

    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /** Label mode tracking yang enak dibaca di UI. */
    public function trackingModeLabel(): string
    {
        return $this->tracking_mode === 'weight' ? 'Timbang / Curah' : 'Satuan Diskrit';
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Hitung ulang harga pokok rata-rata tertimbang setelah ada barang masuk.
     * Dipanggil dari proses konfirmasi penerimaan pembelian (PurchaseOrderItem).
     */
    public function recalculateAverageCost(float $incomingQty, int $incomingUnitCost): int
    {
        $currentStock = (float) $this->stock;
        $currentAvgCost = (int) $this->average_cost;

        if ($currentStock <= 0) {
            return $incomingUnitCost;
        }

        $newAvgCost = (($currentStock * $currentAvgCost) + ($incomingQty * $incomingUnitCost))
            / ($currentStock + $incomingQty);

        return (int) round($newAvgCost);
    }

    /**
     * Pecah sebuah kuantitas (dalam satuan dasar) jadi kombinasi satuan dari
     * yang TERBESAR ke TERKECIL — supaya angka besar di satuan dasar enak
     * dibaca. Contoh (1 dus=288 sachet, 1 renceng=12 sachet, dasar=sachet):
     *   290 -> [['dus', 1], ['sachet', 2]]      (renceng dilewati, sisanya 0)
     *    20 -> [['renceng', 1], ['sachet', 8]]
     *
     * PENTING: relasi `units` harus SUDAH di-eager-load oleh controller
     * pemanggil (bukan lazy-load di sini) — kalau tidak, dan environment
     * mengaktifkan `Model::preventLazyLoading()`, ini akan melempar
     * LazyLoadingViolationException.
     *
     * Hanya berlaku untuk mode `unit`. Untuk mode `weight` (timbang/curah),
     * breakdown berjenjang tidak relevan karena kuantitasnya kontinu/bebas —
     * caller sebaiknya cek `tracking_mode` dulu atau langsung pakai formatStock().
     */
    public function stockBreakdown(float $quantity): array
    {
        $units = $this->units->sortByDesc('conversion_to_base')->values();

        if ($units->isEmpty()) {
            return [['unit_name' => '', 'qty' => round($quantity, 3)]];
        }

        $remaining = round($quantity, 3);
        $result = [];

        foreach ($units as $unit) {
            $conversion = (float) $unit->conversion_to_base;
            if ($conversion <= 0) {
                continue;
            }

            if ($unit->is_base_unit) {
                // Baris dasar selalu jadi "penampung" sisa akhir — termasuk
                // kalau sisanya pecahan (jarang, tapi mungkin untuk data lama).
                // Kalau belum ada satuan lain yang kepakai sama sekali (misal
                // stok 0), tetap tampilkan "0 <satuan dasar>" daripada kosong.
                if ($remaining > 0.0001 || empty($result)) {
                    $result[] = ['unit_name' => $unit->unit_name, 'qty' => $remaining];
                }
                continue;
            }

            $count = floor(($remaining + 0.0001) / $conversion);
            if ($count >= 1) {
                $result[] = ['unit_name' => $unit->unit_name, 'qty' => $count];
                $remaining = round($remaining - ($count * $conversion), 3);
            }
        }

        return $result;
    }

    /**
     * Format kuantitas (satuan dasar) jadi teks siap tampil di UI, breakdown
     * berjenjang dari satuan terbesar ke terkecil — berlaku untuk KEDUA mode
     * (`unit` maupun `weight`). Sisa akhir di satuan dasar ditampilkan apa
     * adanya termasuk kalau pecahan (misal "1 kg 500.75 gram"), karena mode
     * `weight` memang mendukung kuantitas pecahan bebas.
     */
    public function formatStock(float $quantity): string
    {
        // Ambil satuan dasar dari collection $units yang sudah ter-load (bukan
        // lewat relasi baseUnit() terpisah), supaya cukup satu relasi ('units')
        // saja yang perlu di-eager-load oleh controller pemanggil.
        $base = $this->units->firstWhere('is_base_unit', true);
        $trim = fn (float $n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');

        if (! $base) {
            return $trim($quantity);
        }

        return collect($this->stockBreakdown($quantity))
            ->map(fn ($row) => $trim($row['qty']) . ' ' . $row['unit_name'])
            ->implode(' ');
    }
}