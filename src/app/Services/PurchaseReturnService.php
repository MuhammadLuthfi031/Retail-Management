<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu pembuatan & penyelesaian retur ke supplier (retur pembelian).
 *
 * Invarian yang dijaga di sini:
 *  - Total qty (satuan DASAR) yang diretur per item PO tidak pernah melebihi qty
 *    yang benar-benar DITERIMA — dihitung ulang di dalam lock baris PO, bukan dari form.
 *    Satuan retur boleh beda dari satuan beli (beli 5 dus, retur 3 sachet).
 *  - Nilai retur dihitung SERVER dari HARGA BELI di PO (bukan average_cost), proporsional
 *    qty dasar; retur yang menghabiskan sisa mengambil SISA nilai supaya total nilai per
 *    item tepat = harga beli x qty diterima (tanpa selisih pembulatan).
 *  - Stok berkurang HANYA lewat StockMovement::record() (type 'return_out'); stok tidak
 *    pernah minus. average_cost TIDAK diubah (keputusan desain: lihat diskusi retur supplier).
 *  - purchase_orders (status, total_amount) dan quantity_received TIDAK diubah.
 *  - Idempotent per idempotency_key: kirim ulang mengembalikan retur yang sama.
 *
 * Semua perbandingan kuantitas memakai integer per-seribu agar bebas error float.
 */
class PurchaseReturnService
{
    public const RETURNABLE_STATUSES = ['partially_received', 'received'];

    /** 'replacement' (barang pengganti) menyusul di Tahap 2b lewat aksi "Terima pengganti". */
    public const SETTLEMENT_TYPES = ['refund', 'credit'];

    /**
     * @param  array<int|string, array{qty?: mixed, unit_id?: mixed}>  $lines  dikunci oleh purchase_order_item_id
     * @return PurchaseReturn  ->wasRecentlyCreated === false berarti REPLAY dari request yang sama
     */
    public function process(
        PurchaseOrder $po,
        array $lines,
        string $reason,
        string $idempotencyKey,
        int $userId
    ): PurchaseReturn {
        if ($existing = $this->findReplay($idempotencyKey, $po, $userId)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($po, $lines, $reason, $idempotencyKey, $userId) {
                // Kunci baris PO: retur bersamaan (dan penerimaan barang, yang juga mengunci PO)
                // dipaksa antre sehingga hitungan "sisa" di bawah selalu terkini.
                $locked = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

                if ($existing = $this->findReplay($idempotencyKey, $locked, $userId)) {
                    return $existing;
                }

                if (! in_array($locked->status, self::RETURNABLE_STATUSES, true)) {
                    throw ValidationException::withMessages([
                        'items' => 'PO ini belum ada barang yang diterima, tidak bisa diretur.',
                    ]);
                }

                $states = $this->lineStates($locked);
                $prepared = $this->prepareLines($states, $lines);
                $this->assertStockSufficient($prepared);

                $return = PurchaseReturn::createWithUniqueNumber([
                    'idempotency_key' => $idempotencyKey,
                    'purchase_order_id' => $locked->id,
                    'user_id' => $userId,
                    'reason' => $reason,
                    'total_value' => array_sum(array_column($prepared, 'value')),
                    'status' => 'pending',
                ]);

                foreach ($prepared as $line) {
                    $item = $line['item'];
                    $unit = $line['unit'];

                    PurchaseReturnItem::create([
                        'purchase_return_id' => $return->id,
                        'purchase_order_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name,
                        'unit_name' => $unit->unit_name,
                        'unit_conversion' => $unit->conversion_to_base,
                        'quantity' => $line['qty_milli'] / 1000,
                        'quantity_base' => $line['base_milli'] / 1000,
                        'value' => $line['value'],
                    ]);

                    StockMovement::record(
                        product: $item->product,
                        type: 'return_out',
                        quantity: $line['base_milli'] / 1000,
                        userId: $userId,
                        reference: $return->return_number,
                        note: "Retur ke supplier PO {$locked->po_number}: " . $this->fmt($line['qty_milli']) . " {$unit->unit_name}",
                    );
                }

                return $return;
            });
        } catch (QueryException $e) {
            // Jaring terakhir: constraint unique idempotency_key menolak INSERT karena
            // request kembar baru saja commit.
            if ($e->getCode() === '23000' && ($existing = $this->findReplay($idempotencyKey, $po, $userId))) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Keadaan tiap item PO yang SUDAH ada barang diterima: qty diterima/diretur/sisa (satuan
     * dasar, per-seribu) dan nilainya. Dipakai form (tampilan) DAN process() (validasi di
     * dalam lock) agar angkanya pasti sama.
     *
     * @return Collection<int, array{item: \App\Models\PurchaseOrderItem, received_base_milli: int, returned_base_milli: int, remaining_base_milli: int, total_value: int, returned_value: int, remaining_value: int, base_unit_name: string}>
     */
    public function lineStates(PurchaseOrder $po): Collection
    {
        $items = $po->items()->with(['product.units', 'productUnit'])->orderBy('id')->get();

        $returned = PurchaseReturnItem::query()
            ->whereIn('purchase_order_item_id', $items->pluck('id'))
            ->selectRaw('purchase_order_item_id, SUM(quantity_base) as base, SUM(value) as value')
            ->groupBy('purchase_order_item_id')
            ->get()
            ->keyBy('purchase_order_item_id');

        return $items
            ->filter(fn ($item) => $this->milli($item->quantity_received) > 0)
            ->mapWithKeys(function ($item) use ($returned) {
                $receivedBase = (int) round(((float) $item->quantity_received) * (float) $item->productUnit->conversion_to_base * 1000);
                $totalValue = (int) round(((int) $item->unit_price) * (float) $item->quantity_received);

                $row = $returned->get($item->id);
                $returnedBase = $row ? $this->milli($row->base) : 0;
                $returnedValue = $row ? (int) $row->value : 0;

                return [$item->id => [
                    'item' => $item,
                    'received_base_milli' => $receivedBase,
                    'returned_base_milli' => $returnedBase,
                    'remaining_base_milli' => max(0, $receivedBase - $returnedBase),
                    'total_value' => $totalValue,
                    'returned_value' => $returnedValue,
                    'remaining_value' => max(0, $totalValue - $returnedValue),
                    'base_unit_name' => $item->product->units->firstWhere('is_base_unit', true)?->unit_name ?? 'satuan dasar',
                ]];
            });
    }

    /** Apakah PO ini masih punya barang yang bisa diretur (untuk menampilkan tombol "Retur Barang"). */
    public function canReturn(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::RETURNABLE_STATUSES, true)
            && $this->lineStates($po)->contains(fn ($s) => $s['remaining_base_milli'] > 0);
    }

    /** Admin menandai retur selesai (refund / potong tagihan). Hanya dari status 'pending'. */
    public function settle(PurchaseReturn $return, string $type, ?string $note, int $userId): PurchaseReturn
    {
        if (! in_array($type, self::SETTLEMENT_TYPES, true)) {
            throw ValidationException::withMessages(['settlement_type' => 'Jenis penyelesaian tidak valid.']);
        }

        return DB::transaction(function () use ($return, $type, $note, $userId) {
            $locked = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Retur ini sudah diselesaikan sebelumnya.']);
            }

            $locked->update([
                'status' => 'settled',
                'settlement_type' => $type,
                'settlement_note' => $note,
                'settled_by' => $userId,
                'settled_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * @param  Collection<int, array>  $states
     * @param  array<int|string, array>  $lines
     * @return list<array{item: \App\Models\PurchaseOrderItem, unit: \App\Models\ProductUnit, qty_milli: int, base_milli: int, value: int}>
     */
    private function prepareLines(Collection $states, array $lines): array
    {
        $prepared = [];

        foreach ($lines as $itemId => $input) {
            $qtyRaw = $input['qty'] ?? null;
            if ($qtyRaw === null || $qtyRaw === '' || $this->milli($qtyRaw) <= 0) {
                continue; // item yang tidak diretur
            }

            $state = $states->get((int) $itemId);
            if (! $state) {
                throw ValidationException::withMessages([
                    'items' => 'Ada barang yang bukan bagian dari PO ini atau belum diterima.',
                ]);
            }

            $item = $state['item'];
            $product = $item->product;
            $label = "\"{$product->name}\"";

            // Satuan retur harus salah satu satuan produk ITU (bukan satuan produk lain).
            $unit = $product->units->firstWhere('id', (int) ($input['unit_id'] ?? 0));
            if (! $unit) {
                throw ValidationException::withMessages([
                    'items' => "Pilih satuan retur yang valid untuk {$label}.",
                ]);
            }

            $qtyMilli = $this->milli($qtyRaw);
            $baseMilli = (int) round(((float) $qtyRaw) * (float) $unit->conversion_to_base * 1000);

            if ($baseMilli <= 0) {
                throw ValidationException::withMessages([
                    'items' => "Qty retur {$label} terlalu kecil.",
                ]);
            }

            if ($baseMilli > $state['remaining_base_milli']) {
                throw ValidationException::withMessages([
                    'items' => "Qty retur {$label} melebihi sisa yang bisa diretur ({$this->fmt($state['remaining_base_milli'])} {$state['base_unit_name']}).",
                ]);
            }

            if (! $product->allow_fractional_sale && $baseMilli % 1000 !== 0) {
                throw ValidationException::withMessages([
                    'items' => "Produk {$label} tidak bisa diretur dalam pecahan satuan dasar ({$state['base_unit_name']}).",
                ]);
            }

            $prepared[] = [
                'item' => $item,
                'unit' => $unit,
                'qty_milli' => $qtyMilli,
                'base_milli' => $baseMilli,
                'value' => $this->valueFor($state, $baseMilli),
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
     * Nilai = harga beli x qty diterima, proporsional qty dasar yang diretur. Retur yang
     * menghabiskan sisa mengambil SISA nilai; selain itu dibatasi sisa nilai agar akumulasi
     * pembulatan tidak pernah membuat total nilai melebihi yang dibayar.
     */
    private function valueFor(array $state, int $baseMilli): int
    {
        if ($baseMilli === $state['remaining_base_milli']) {
            return $state['remaining_value'];
        }

        $proportional = (int) round($state['total_value'] * $baseMilli / $state['received_base_milli']);

        return min($proportional, $state['remaining_value']);
    }

    /**
     * Pra-cek stok dengan pesan yang menyebut nama produk (guard resmi tetap di
     * StockMovement::record() setelah lock produk — pesannya generik, tidak menyebut produk).
     * Produk yang sama di beberapa baris dijumlahkan.
     */
    private function assertStockSufficient(array $prepared): void
    {
        $needed = [];
        foreach ($prepared as $line) {
            $pid = $line['item']->product_id;
            $needed[$pid] = ($needed[$pid] ?? 0) + $line['base_milli'];
        }

        $stocks = Product::whereIn('id', array_keys($needed))->pluck('stock', 'id');

        foreach ($prepared as $line) {
            $pid = $line['item']->product_id;
            if (! isset($needed[$pid])) {
                continue; // sudah dicek lewat baris lain produk yang sama
            }

            $stockMilli = $this->milli($stocks[$pid] ?? 0);
            if ($needed[$pid] > $stockMilli) {
                $unitName = $line['item']->product->units->firstWhere('is_base_unit', true)?->unit_name ?? 'satuan dasar';

                throw ValidationException::withMessages([
                    'items' => "Stok \"{$line['item']->product->name}\" tidak cukup untuk diretur (stok saat ini {$this->fmt($stockMilli)} {$unitName}, diminta {$this->fmt($needed[$pid])} {$unitName}). Barang yang sudah terjual tidak bisa dikembalikan ke supplier.",
                ]);
            }

            unset($needed[$pid]);
        }
    }

    private function findReplay(string $key, PurchaseOrder $po, int $userId): ?PurchaseReturn
    {
        $existing = PurchaseReturn::where('idempotency_key', $key)->first();

        if (! $existing) {
            return null;
        }

        // Key yang sama untuk PO/pelaku lain bukan replay yang sah: tolak jelas.
        if ((int) $existing->purchase_order_id !== (int) $po->id || (int) $existing->user_id !== $userId) {
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