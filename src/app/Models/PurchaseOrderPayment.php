<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu kali status pembayaran PO berubah, lengkap dengan bukti
 * transfer/pembayaran yang di-upload admin saat itu. Lihat catatan di
 * migrasinya soal kenapa ini tabel log, bukan cuma kolom foto di
 * purchase_orders.
 */
class PurchaseOrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'from_status',
        'to_status',
        'proof_path',
        'uploaded_by',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}