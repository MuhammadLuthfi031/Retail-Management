<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'product_name',
        'unit_name',
        'unit_conversion',
        'price',
        'unit_cost',
        'discount_amount',
        'quantity',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_conversion' => 'decimal:3',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Kuantitas yang terjual, dikonversi ke satuan dasar produk. */
    public function quantityInBaseUnit(): float
    {
        return round($this->quantity * $this->unit_conversion, 3);
    }

    /**
     * Harga Pokok Penjualan (HPP) baris ini = unit_cost (snapshot per satuan
     * dasar saat terjual) x kuantitas dalam satuan dasar. Basis Laporan
     * Laba/Rugi (§7.3). Null kalau baris ini dibuat sebelum kolom unit_cost
     * ada — pemanggil (LaporanController) yang menentukan fallback-nya.
     */
    public function costOfGoodsSold(): ?int
    {
        return $this->unit_cost === null
            ? null
            : (int) round($this->unit_cost * $this->quantityInBaseUnit());
    }
}