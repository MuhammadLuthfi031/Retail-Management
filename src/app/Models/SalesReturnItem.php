<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnItem extends Model
{
    protected $fillable = [
        'sales_return_id',
        'transaction_detail_id',
        'product_id',
        'product_name',
        'unit_name',
        'unit_conversion',
        'quantity',
        'condition',
        'refund_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_conversion' => 'decimal:3',
        ];
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function transactionDetail()
    {
        return $this->belongsTo(TransactionDetail::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}