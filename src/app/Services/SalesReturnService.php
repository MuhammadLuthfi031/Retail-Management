<?php

namespace App\Services;

use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu pembuatan retur penjualan (retur pelanggan).
 *
 * Invarian yang dijaga di sini:
 *  - Total qty yang diretur per baris transaksi TIDAK PERNAH melebihi qty terjual
 *    (dihitung ulang di dalam lock baris transaksi, bukan dari data form).
 *  - Refund dihitung SERVER dari subtotal baris (sudah termasuk diskon baris),
 *    proporsional qty; retur terakhir sebuah baris mengambil SISA refund supaya
 *    total refund per baris tepat sama dengan subtotal (tanpa selisih pembulatan).
 *  - Barang 'resellable' kembali ke stok HANYA lewat StockMovement::record()
 *    (type 'return_in'); 'damaged' tidak menyentuh stok. average_cost tidak berubah.
 *  - Idempotent per idempotency_key: kirim ulang (dobel-klik/retry) mengembalikan
 *    retur yang sama, TIDAK membuat retur kedua.
 *  - transactions.status TIDAK diubah (lihat catatan di SalesReturn).
 *
 * Semua perbandingan kuantitas memakai integer per-seribu (decimal(12,3) -> int)
 * supaya tidak terkena error float.
 */
class SalesReturnService
{
    public const REFUND_METHODS = ['cash', 'debit', 'qris', 'transfer'];
    public const CONDITIONS = ['resellable', 'damaged'];

    /**
     * @param  array<int|string, array{qty?: mixed, condition?: mixed}>  $lines  dikunci oleh transaction_detail_id
     * @return SalesReturn  ->wasRecentlyCreated === false berarti ini REPLAY dari request yang sama
     */
    public function process(
        Transaction $transaction,
        array $lines,
        string $refundMethod,
        string $reason,
        string $idempotencyKey,
        int $userId
    ): SalesReturn {
        if (! in_array($refundMethod, self::REFUND_METHODS, true)) {
            throw ValidationException::withMessages(['refund_method' => 'Metode refund tidak valid.']);
        }

        // Jalur cepat: request yang sama sudah pernah sukses -> kembalikan hasilnya,
        // jangan validasi ulang (qty yang tersisa sudah berkurang oleh retur itu sendiri).
        if ($existing = $this->findReplay($idempotencyKey, $transaction, $userId)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($transaction, $lines, $refundMethod, $reason, $idempotencyKey, $userId) {
                // Kunci baris transaksi: dua retur bersamaan atas transaksi yang sama
                // dipaksa antre, sehingga hitungan "sisa qty" di bawah selalu terkini.
                $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

                // Cek ulang replay SETELAH lock: request kembar yang menunggu lock
                // baru melihat hasil request pertama di titik ini.
                if ($existing = $this->findReplay($idempotencyKey, $locked, $userId)) {
                    return $existing;
                }

                if ($locked->status !== 'completed') {
                    throw ValidationException::withMessages([
                        'items' => 'Transaksi ini tidak berstatus selesai, tidak bisa diretur.',
                    ]);
                }

                $states = $this->lineStates($locked);
                $prepared = $this->prepareLines($states, $lines);

                $totalRefund = array_sum(array_column($prepared, 'refund'));

                $return = SalesReturn::createWithUniqueNumber([
                    'idempotency_key' => $idempotencyKey,
                    'transaction_id' => $locked->id,
                    'user_id' => $userId,
                    'refund_method' => $refundMethod,
                    'total_refund' => $totalRefund,
                    'reason' => $reason,
                ]);

                foreach ($prepared as $line) {
                    /** @var TransactionDetail $detail */
                    $detail = $line['detail'];

                    SalesReturnItem::create([
                        'sales_return_id' => $return->id,
                        'transaction_detail_id' => $detail->id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product_name,
                        'unit_name' => $detail->unit_name,
                        'unit_conversion' => $detail->unit_conversion,
                        'quantity' => $line['qty_milli'] / 1000,
                        'condition' => $line['condition'],
                        'refund_amount' => $line['refund'],
                    ]);

                    if ($line['condition'] === 'resellable') {
                        $qtyInBase = round(($line['qty_milli'] / 1000) * (float) $detail->unit_conversion, 3);

                        StockMovement::record(
                            product: $detail->product,
                            type: 'return_in',
                            quantity: $qtyInBase,
                            userId: $userId,
                            reference: $return->return_number,
                            note: "Retur pelanggan: " . $this->fmt($line['qty_milli']) . " {$detail->unit_name} dari {$locked->invoice_number}",
                        );
                    }
                }

                return $return;
            });
        } catch (QueryException $e) {
            // Jaring terakhir: constraint unique idempotency_key menolak INSERT karena
            // request kembar baru saja commit. Kalau memang itu penyebabnya, kembalikan
            // hasil request pertama; selain itu biarkan error naik.
            if ($e->getCode() === '23000' && ($existing = $this->findReplay($idempotencyKey, $transaction, $userId))) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Keadaan tiap baris transaksi: qty terjual/sudah diretur/sisa (per-seribu) dan
     * refund yang sudah dikeluarkan. Dipakai form retur (tampilan) DAN process()
     * (validasi di dalam lock) supaya angkanya pasti sama.
     *
     * @return Collection<int, array{detail: TransactionDetail, sold_milli: int, returned_milli: int, remaining_milli: int, refunded: int, remaining_refund: int}>
     */
    public function lineStates(Transaction $transaction): Collection
    {
        $details = $transaction->details()->with('product')->orderBy('id')->get();

        $returned = SalesReturnItem::query()
            ->whereIn('transaction_detail_id', $details->pluck('id'))
            ->selectRaw('transaction_detail_id, SUM(quantity) as qty, SUM(refund_amount) as refunded')
            ->groupBy('transaction_detail_id')
            ->get()
            ->keyBy('transaction_detail_id');

        return $details->mapWithKeys(function (TransactionDetail $d) use ($returned) {
            $sold = $this->milli($d->quantity);
            $row = $returned->get($d->id);
            $returnedMilli = $row ? $this->milli($row->qty) : 0;
            $refunded = $row ? (int) $row->refunded : 0;

            return [$d->id => [
                'detail' => $d,
                'sold_milli' => $sold,
                'returned_milli' => $returnedMilli,
                'remaining_milli' => max(0, $sold - $returnedMilli),
                'refunded' => $refunded,
                'remaining_refund' => max(0, (int) $d->subtotal - $refunded),
            ]];
        });
    }

    /**
     * @param  Collection<int, array>  $states
     * @param  array<int|string, array>  $lines
     * @return list<array{detail: TransactionDetail, qty_milli: int, condition: string, refund: int}>
     */
    private function prepareLines(Collection $states, array $lines): array
    {
        $prepared = [];

        foreach ($lines as $detailId => $input) {
            $qtyRaw = $input['qty'] ?? null;
            if ($qtyRaw === null || $qtyRaw === '' || $this->milli($qtyRaw) <= 0) {
                continue; // baris yang tidak diretur
            }

            $state = $states->get((int) $detailId);
            if (! $state) {
                throw ValidationException::withMessages([
                    'items' => 'Ada barang yang bukan bagian dari transaksi ini.',
                ]);
            }

            $detail = $state['detail'];
            $label = "\"{$detail->product_name}\" ({$detail->unit_name})";
            $qtyMilli = $this->milli($qtyRaw);

            $condition = $input['condition'] ?? null;
            if (! in_array($condition, self::CONDITIONS, true)) {
                throw ValidationException::withMessages([
                    'items' => "Pilih kondisi barang untuk {$label}.",
                ]);
            }

            if ($qtyMilli > $state['remaining_milli']) {
                throw ValidationException::withMessages([
                    'items' => "Qty retur {$label} melebihi sisa yang bisa diretur ({$this->fmt($state['remaining_milli'])}).",
                ]);
            }

            if (! $detail->product->allow_fractional_sale && $qtyMilli % 1000 !== 0) {
                throw ValidationException::withMessages([
                    'items' => "Produk {$label} tidak boleh diretur dengan kuantitas pecahan.",
                ]);
            }

            $prepared[] = [
                'detail' => $detail,
                'qty_milli' => $qtyMilli,
                'condition' => $condition,
                'refund' => $this->refundFor($state, $qtyMilli),
            ];
        }

        if ($prepared === []) {
            throw ValidationException::withMessages([
                'items' => 'Isi qty retur minimal pada satu barang.',
            ]);
        }

        return $prepared;
    }

    /**
     * Refund = subtotal baris x (qty retur / qty terjual), dibulatkan. Retur yang
     * menghabiskan sisa qty mengambil SISA refund (subtotal - yang sudah dikembalikan)
     * supaya jumlah refund seluruh retur sebuah baris persis = subtotal.
     */
    private function refundFor(array $state, int $qtyMilli): int
    {
        if ($qtyMilli === $state['remaining_milli']) {
            return $state['remaining_refund'];
        }

        $proportional = (int) round(((int) $state['detail']->subtotal) * $qtyMilli / $state['sold_milli']);

        return min($proportional, $state['remaining_refund']);
    }

    private function findReplay(string $key, Transaction $transaction, int $userId): ?SalesReturn
    {
        $existing = SalesReturn::where('idempotency_key', $key)->first();

        if (! $existing) {
            return null;
        }

        // Key yang sama tapi untuk transaksi/pelaku lain bukan replay yang sah:
        // jangan bocorkan datanya, tolak jelas.
        if ((int) $existing->transaction_id !== (int) $transaction->id || (int) $existing->user_id !== $userId) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Kode retur ini sudah dipakai. Muat ulang halaman lalu coba lagi.',
            ]);
        }

        return $existing;
    }

    private function milli(mixed $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    private function fmt(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, ',', ''), '0'), ',');
    }
}