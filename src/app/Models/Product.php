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
}