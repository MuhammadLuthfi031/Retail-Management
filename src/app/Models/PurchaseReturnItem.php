<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_order_item_id',
        'product_id',
        'product_name',
        'unit_name',
        'unit_conversion',
        'quantity',
        'quantity_base',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'unit_conversion' => 'decimal:3',
            'quantity' => 'decimal:3',
            'quantity_base' => 'decimal:3',
        ];
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}