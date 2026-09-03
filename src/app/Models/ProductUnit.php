<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'unit_name',
        'conversion_to_base',
        'barcode',
        'selling_price',
        'is_base_unit',
        'is_purchase_unit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'decimal:3',
            'is_base_unit' => 'boolean',
            'is_purchase_unit' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Konversi kuantitas dalam satuan INI ke kuantitas satuan dasar. */
    public function toBase(float $quantity): float
    {
        return round($quantity * $this->conversion_to_base, 3);
    }

    /** Konversi kuantitas satuan dasar ke kuantitas dalam satuan INI. */
    public function fromBase(float $baseQuantity): float
    {
        return round($baseQuantity / $this->conversion_to_base, 3);
    }
}