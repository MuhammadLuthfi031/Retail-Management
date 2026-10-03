<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\SalesReturnService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Retur penjualan (retur pelanggan) — Tahap 1a.
 *
 * Fixture standar: produk stok 100 (satuan dasar), average_cost 800; satu transaksi
 * menjual 3 renceng (1 renceng = 12 satuan dasar) @ Rp 11.000 = Rp 33.000.
 * Semua angka di bawah dihitung tangan dari fixture itu.
 */
class SalesReturnTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        $this->hindariIdUserPertama();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Product::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: Transaction, 1: TransactionDetail, 2: Product} */
    private function transaksiRenceng(int $qty = 3, int $subtotal = 33000, int $discount = 0, array $productAttr = []): array
    {
        $product = $this->makeProduct(array_merge(['stock' => 100, 'average_cost' => 800], $productAttr));

        $trx = $this->makeTransaction(
            ['grand_total' => $subtotal, 'total_amount' => $subtotal + $discount, 'discount_amount' => $discount],
            [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_name' => 'renceng',
                'unit_conversion' => 12,
                'price' => 11000,
                'unit_cost' => 800,
                'discount_amount' => $discount,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ]]
        );

        return [$trx, $trx->details()->firstOrFail(), $product];
    }

    private function retur(Transaction $trx, array $items, array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(
            route('kasir.riwayat.retur.store', $trx),
            array_merge([
                'idempotency_key' => (string) Str::uuid(),
                'refund_method' => 'cash',
                'reason' => 'Kemasan rusak',
                'items' => $items,
            ], $override)
        );
    }

    private function line(TransactionDetail $d, string|int|float $qty, string $condition = 'resellable'): array
    {
        return [$d->id => ['qty' => (string) $qty, 'condition' => $condition]];
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, SalesReturn::count(), 'SalesReturn tidak boleh terbuat');
        $this->assertSame(0, SalesReturnItem::count(), 'SalesReturnItem tidak boleh terbuat');
        $this->assertSame(0, StockMovement::where('type', 'return_in')->count(), 'Stok tidak boleh bergerak');
    }

    // ----------------------------------------------------------- akses (role)

    public function test_gudang_tidak_boleh_memproses_retur_sama_sekali(): void
    {
        [$trx] = $this->transaksiRenceng();
        $gudang = User::factory()->gudang()->create();
        $this->actingAs($gudang);

        $this->getJson(route('kasir.riwayat.retur.form', $trx))->assertForbidden();
        $this->postJson(route('kasir.riwayat.retur.store', $trx), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_kasir_dan_gudang_tidak_boleh_membuka_daftar_retur_admin(): void
    {
        [$trx] = $this->transaksiRenceng();
        $return = SalesReturn::forceCreate([
            'return_number' => 'RTN-X', 'idempotency_key' => (string) Str::uuid(),
            'transaction_id' => $trx->id, 'user_id' => $this->admin->id,
            'refund_method' => 'cash', 'total_refund' => 0, 'reason' => 'x',
        ]);

        foreach (['kasir', 'gudang'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->get(route('admin.retur-penjualan.index'))->assertForbidden();
            $this->get(route('admin.retur-penjualan.show', $return))->assertForbidden();
        }
    }

    public function test_tamu_ditolak(): void
    {
        [$trx] = $this->transaksiRenceng();

        $this->get(route('admin.retur-penjualan.index'))->assertRedirect(route('login'));
        $this->getJson(route('kasir.riwayat.retur.form', $trx))->assertUnauthorized();
        $this->postJson(route('kasir.riwayat.retur.store', $trx), [])->assertUnauthorized();
    }

    public function test_kasir_boleh_meretur_transaksi_miliknya_dan_tercatat_atas_namanya(): void
    {
        $kasir = User::factory()->kasir()->create();
        [$trx, $detail, $product] = $this->transaksiRenceng();
        $trx->forceFill(['user_id' => $kasir->id])->save();

        $this->actingAs($kasir)->getJson(route('kasir.riwayat.retur.form', $trx))
            ->assertOk()->assertJsonStructure(['html']);

        $this->retur($trx, $this->line($detail, 2), [], $kasir)->assertOk();

        $return = SalesReturn::firstOrFail();
        $this->assertSame($kasir->id, $return->user_id, 'Pelaku retur = kasir yang login');
        $this->assertSame($kasir->id, StockMovement::where('type', 'return_in')->firstOrFail()->user_id);
        $this->assertEquals(124, $this->stockOf($product)); // 100 + 2 x 12
    }

    public function test_kasir_tidak_boleh_meretur_transaksi_kasir_lain(): void
    {
        $kasir = User::factory()->kasir()->create();
        [$trx, $detail, $product] = $this->transaksiRenceng(); // milik kasir lain

        $this->actingAs($kasir)->getJson(route('kasir.riwayat.retur.form', $trx))
            ->assertForbidden()->assertJsonPath('message', 'Anda tidak punya akses ke transaksi ini.');

        $this->retur($trx, $this->line($detail, 1), [], $kasir)
            ->assertForbidden()->assertJsonPath('message', 'Anda tidak punya akses ke transaksi ini.');

        $this->assertNothingCreated();
        $this->assertEquals(100, $this->stockOf($product));
    }

    public function test_admin_boleh_meretur_transaksi_kasir_mana_pun(): void
    {
        [$trx, $detail] = $this->transaksiRenceng(); // milik kasir lain

        $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))->assertOk();
        $this->retur($trx, $this->line($detail, 1))->assertOk();

        $this->assertSame($this->admin->id, SalesReturn::firstOrFail()->user_id);
    }

    // ------------------------------------------------------ retur sukses (inti)

    public function test_retur_penuh_layak_jual_mengembalikan_stok_dan_mencatat_semuanya(): void
    {
        [$trx, $detail, $product] = $this->transaksiRenceng();

        $response = $this->retur($trx, $this->line($detail, 3), ['refund_method' => 'qris', 'reason' => 'Salah beli']);

        $return = SalesReturn::firstOrFail();
        $response->assertOk()
            ->assertJsonPath('return_number', 'RTN-20261014-0001')
            ->assertJsonPath('created', true)
            ->assertJsonPath('message', 'Retur RTN-20261014-0001 berhasil dicatat.');

        // Header
        $this->assertSame('RTN-20261014-0001', $return->return_number);
        $this->assertSame($trx->id, $return->transaction_id);
        $this->assertSame($this->admin->id, $return->user_id);
        $this->assertSame('qris', $return->refund_method);
        $this->assertSame(33000, (int) $return->total_refund);
        $this->assertSame('Salah beli', $return->reason);

        // Baris + snapshot
        $item = $return->items()->firstOrFail();
        $this->assertSame($detail->id, $item->transaction_detail_id);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($detail->product_name, $item->product_name);
        $this->assertSame('renceng', $item->unit_name);
        $this->assertEquals(12, $item->unit_conversion);
        $this->assertEquals(3, $item->quantity);
        $this->assertSame('resellable', $item->condition);
        $this->assertSame(33000, (int) $item->refund_amount);

        // Stok: 100 + 3 renceng x 12 = 136
        $this->assertEquals(136, $this->stockOf($product));

        $movement = StockMovement::where('type', 'return_in')->firstOrFail();
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame($this->admin->id, $movement->user_id);
        $this->assertEquals(36, $movement->quantity);
        $this->assertEquals(100, $movement->stock_before);
        $this->assertEquals(136, $movement->stock_after);
        $this->assertSame('RTN-20261014-0001', $movement->reference);

        // Invarian: status transaksi TIDAK berubah, average_cost TIDAK berubah.
        $this->assertSame('completed', $trx->fresh()->status);
        $this->assertSame(800, (int) Product::find($product->id)->average_cost);
    }

    public function test_barang_rusak_tidak_menyentuh_stok_tapi_refund_dan_riwayat_tetap_tercatat(): void
    {
        [$trx, $detail, $product] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 2, 'damaged'));

        $item = SalesReturnItem::firstOrFail();
        $this->assertSame('damaged', $item->condition);
        $this->assertSame(22000, (int) $item->refund_amount);
        $this->assertSame(22000, (int) SalesReturn::firstOrFail()->total_refund);

        $this->assertEquals(100, $this->stockOf($product));
        $this->assertSame(0, StockMovement::where('type', 'return_in')->count());
    }

    public function test_retur_beberapa_baris_sekaligus_menjumlahkan_refund_dan_hanya_layak_jual_masuk_stok(): void
    {
        $p1 = $this->makeProduct(['stock' => 100, 'average_cost' => 800]);
        $p2 = $this->makeProduct(['stock' => 50, 'average_cost' => 500]);

        $trx = $this->makeTransaction(['grand_total' => 33000 + 2000], [
            ['product_id' => $p1->id, 'product_name' => 'A', 'unit_name' => 'renceng', 'unit_conversion' => 12, 'price' => 11000, 'quantity' => 3, 'subtotal' => 33000],
            ['product_id' => $p2->id, 'product_name' => 'B', 'unit_name' => 'sachet', 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
        ]);
        [$d1, $d2] = $trx->details()->orderBy('id')->get()->all();

        $this->retur($trx, [
            $d1->id => ['qty' => '1', 'condition' => 'resellable'],
            $d2->id => ['qty' => '2', 'condition' => 'damaged'],
        ]);

        $this->assertSame(11000 + 2000, (int) SalesReturn::firstOrFail()->total_refund);
        $this->assertEquals(112, $this->stockOf($p1));  // +12
        $this->assertEquals(50, $this->stockOf($p2));   // rusak: tidak berubah
        $this->assertSame(1, StockMovement::where('type', 'return_in')->count());
    }

    public function test_baris_ber_qty_nol_atau_kosong_diabaikan(): void
    {
        $p1 = $this->makeProduct(['stock' => 10]);
        $p2 = $this->makeProduct(['stock' => 10]);
        $trx = $this->makeTransaction([], [
            ['product_id' => $p1->id, 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
            ['product_id' => $p2->id, 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
        ]);
        [$d1, $d2] = $trx->details()->orderBy('id')->get()->all();

        $this->retur($trx, [
            $d1->id => ['qty' => '1', 'condition' => 'resellable'],
            $d2->id => ['qty' => '0', 'condition' => 'resellable'],
        ])->assertOk();

        $this->assertSame(1, SalesReturnItem::count());
        $this->assertEquals(10, $this->stockOf($p2));
    }

    // ------------------------------------------------------ hitung refund parsial

    public function test_refund_parsial_proporsional_dan_retur_terakhir_mengambil_sisa(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 1));
        $this->retur($trx, $this->line($detail, 2));

        $refunds = SalesReturnItem::orderBy('id')->pluck('refund_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([11000, 22000], $refunds);
        $this->assertSame(33000, array_sum($refunds));
    }

    public function test_pembulatan_refund_dengan_diskon_baris_total_tepat_sama_dengan_subtotal(): void
    {
        // 3 renceng @11.000 = 33.000, diskon baris 2.000 -> subtotal 31.000.
        // 31.000/3 = 10.333,33 -> retur 1 & 2 masing-masing 10.333; retur ke-3 = sisa 10.334.
        [$trx, $detail] = $this->transaksiRenceng(3, 31000, 2000);

        $this->retur($trx, $this->line($detail, 1));
        $this->retur($trx, $this->line($detail, 1));
        $this->retur($trx, $this->line($detail, 1));

        $refunds = SalesReturnItem::orderBy('id')->pluck('refund_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([10333, 10333, 10334], $refunds);
        $this->assertSame(31000, array_sum($refunds));
    }

    public function test_pembulatan_ke_atas_pada_retur_parsial_dan_sisa_pada_retur_terakhir(): void
    {
        // Subtotal 10.000 untuk 3 renceng. Retur 2 -> 10.000 x 2/3 = 6.666,67 -> 6.667
        // (round, BUKAN floor 6.666). Retur terakhir (1) = sisa 3.333. Total 10.000.
        [$trx, $detail] = $this->transaksiRenceng(3, 10000, 23000);

        $this->retur($trx, $this->line($detail, 2));
        $this->retur($trx, $this->line($detail, 1));

        $this->assertSame([6667, 3333], SalesReturnItem::orderBy('id')->pluck('refund_amount')->map(fn ($v) => (int) $v)->all());
    }

    public function test_refund_satu_baris_tidak_pernah_melebihi_subtotalnya_walau_pembulatan_menumpuk(): void
    {
        // 5 sachet @Rp 1 dengan diskon Rp 2 -> subtotal Rp 3. Tiap retur 1 sachet:
        // 3/5 = 0,6 -> dibulatkan 1. Tanpa batas "sisa refund", retur ke-4 ikut
        // mengembalikan Rp 1 padahal sisa sudah Rp 0 (total jadi 4 > subtotal 3).
        $product = $this->makeProduct(['stock' => 10]);
        $trx = $this->makeTransaction(['grand_total' => 3], [[
            'product_id' => $product->id, 'product_name' => 'Permen', 'unit_name' => 'sachet',
            'unit_conversion' => 1, 'price' => 1, 'discount_amount' => 2, 'quantity' => 5, 'subtotal' => 3,
        ]]);
        $detail = $trx->details()->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $this->retur($trx, $this->line($detail, 1))->assertOk();
        }

        $refunds = SalesReturnItem::orderBy('id')->pluck('refund_amount')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([1, 1, 1, 0, 0], $refunds);
        $this->assertSame(3, array_sum($refunds));
    }

    public function test_barang_curah_boleh_diretur_pecahan_dan_stok_kembali_sesuai_konversi(): void
    {
        // 2,5 kg @Rp 20.000/kg = 50.000. Retur 1,25 kg -> refund 25.000, stok +1,25.
        $product = $this->makeProduct(
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 10, 'average_cost' => 15000],
            [['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 20000, 'is_base_unit' => true]]
        );
        $trx = $this->makeTransaction(['grand_total' => 50000], [[
            'product_id' => $product->id, 'product_name' => 'Gula Curah', 'unit_name' => 'kg',
            'unit_conversion' => 1, 'price' => 20000, 'quantity' => 2.5, 'subtotal' => 50000,
        ]]);
        $detail = $trx->details()->firstOrFail();

        $this->retur($trx, $this->line($detail, '1.25'))->assertOk();

        $this->assertSame(25000, (int) SalesReturn::firstOrFail()->total_refund);
        $this->assertEquals(11.25, $this->stockOf($product));
    }

    // ------------------------------------------------- batas qty (over-return)

    public function test_qty_melebihi_terjual_ditolak_dan_tidak_ada_yang_tersimpan(): void
    {
        [$trx, $detail, $product] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 4))->assertJsonValidationErrors([
            'items' => 'Qty retur "' . $detail->product_name . '" (renceng) melebihi sisa yang bisa diretur (3).',
        ]);

        $this->assertNothingCreated();
        $this->assertEquals(100, $this->stockOf($product));
    }

    public function test_batas_kumulatif_antar_retur_dan_tepat_di_batas_lolos(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 2))->assertOk();

        // Sisa = 1. Meminta 2 harus ditolak dengan sisa yang benar (1)...
        $this->retur($trx, $this->line($detail, 2))->assertJsonValidationErrors([
            'items' => 'Qty retur "' . $detail->product_name . '" (renceng) melebihi sisa yang bisa diretur (1).',
        ]);
        $this->assertSame(1, SalesReturn::count());

        // ...sedangkan tepat 1 (batas) harus lolos.
        $this->retur($trx, $this->line($detail, 1))->assertOk();
        $this->assertSame(2, SalesReturn::count());
    }

    public function test_batas_pecahan_selisih_sepersekian_ditolak(): void
    {
        $product = $this->makeProduct(
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 10],
            [['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 20000, 'is_base_unit' => true]]
        );
        $trx = $this->makeTransaction([], [[
            'product_id' => $product->id, 'product_name' => 'Curah', 'unit_name' => 'kg',
            'unit_conversion' => 1, 'price' => 20000, 'quantity' => 2.5, 'subtotal' => 50000,
        ]]);
        $detail = $trx->details()->firstOrFail();

        $this->retur($trx, $this->line($detail, '2.501'))->assertJsonValidationErrors([
            'items' => 'Qty retur "Curah" (kg) melebihi sisa yang bisa diretur (2,5).',
        ]);
        $this->retur($trx, $this->line($detail, '2.500'))->assertOk();
    }

    public function test_produk_non_curah_tidak_boleh_diretur_pecahan(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, '1.5'))->assertJsonValidationErrors([
            'items' => 'Produk "' . $detail->product_name . '" (renceng) tidak boleh diretur dengan kuantitas pecahan.',
        ]);
        $this->assertNothingCreated();
    }

    public function test_qty_raksasa_ditolak_validasi_bukan_diproses_dengan_angka_meluap(): void
    {
        // Float di luar rentang integer di-cast PHP secara tidak terdefinisi; batas
        // `max` di controller adalah pengaman supaya angka seperti ini tidak pernah
        // sampai ke perhitungan per-seribu.
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, str_repeat('9', 25)))
            ->assertJsonValidationErrors(["items.{$detail->id}.qty" => 'Qty retur terlalu besar.']);
        $this->assertNothingCreated();
    }

    public function test_baris_dari_transaksi_lain_ditolak(): void
    {
        [$trx] = $this->transaksiRenceng();
        [, $detailLain] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detailLain, 1))->assertJsonValidationErrors([
            'items' => 'Ada barang yang bukan bagian dari transaksi ini.',
        ]);
        $this->assertNothingCreated();
    }

    // ------------------------------------------------------------- validasi input

    public function test_tidak_ada_qty_sama_sekali_ditolak(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, [$detail->id => ['qty' => '0', 'condition' => 'resellable']])
            ->assertJsonValidationErrors(['items' => 'Isi qty retur minimal pada satu barang.']);
        $this->retur($trx, [$detail->id => ['qty' => '', 'condition' => 'resellable']])
            ->assertJsonValidationErrors(['items' => 'Isi qty retur minimal pada satu barang.']);
        $this->retur($trx, [], ['items' => null])->assertJsonValidationErrors('items');
        $this->assertNothingCreated();
    }

    public function test_kondisi_wajib_dipilih_dan_harus_valid(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, [$detail->id => ['qty' => '1']])->assertJsonValidationErrors([
            'items' => 'Pilih kondisi barang untuk "' . $detail->product_name . '" (renceng).',
        ]);
        $this->retur($trx, [$detail->id => ['qty' => '1', 'condition' => 'hilang']])
            ->assertJsonValidationErrors(["items.{$detail->id}.condition" => 'Kondisi barang tidak valid.']);
        $this->assertNothingCreated();
    }

    public function test_qty_negatif_bukan_angka_dan_lebih_dari_3_desimal_ditolak(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();
        $key = "items.{$detail->id}.qty";

        $this->retur($trx, $this->line($detail, '-1'))->assertJsonValidationErrors([$key => 'Qty retur tidak boleh negatif.']);
        $this->retur($trx, $this->line($detail, 'abc'))->assertJsonValidationErrors([$key => 'Qty retur harus berupa angka.']);
        $this->retur($trx, $this->line($detail, '0.0001'))->assertJsonValidationErrors([$key => 'Qty retur maksimal 3 angka di belakang koma.']);
        $this->assertNothingCreated();
    }

    public function test_metode_refund_alasan_dan_kunci_idempotensi_divalidasi(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();
        $items = $this->line($detail, 1);

        $this->retur($trx, $items, ['refund_method' => 'kredit'])
            ->assertJsonValidationErrors(['refund_method' => 'Metode pengembalian uang tidak valid.']);
        $this->retur($trx, $items, ['reason' => ''])
            ->assertJsonValidationErrors(['reason' => 'Alasan retur wajib diisi.']);
        $this->retur($trx, $items, ['reason' => str_repeat('a', 256)])
            ->assertJsonValidationErrors(['reason' => 'Alasan retur maksimal 255 karakter.']);
        $this->retur($trx, $items, ['idempotency_key' => 'bukan-uuid'])->assertJsonValidationErrors('idempotency_key');
        $this->assertNothingCreated();

        // Batas: alasan tepat 255 karakter lolos.
        $this->retur($trx, $items, ['reason' => str_repeat('a', 255)])->assertOk();
    }

    public function test_transaksi_tidak_selesai_tidak_bisa_diretur_dan_form_ditolak(): void
    {
        foreach (['refunded', 'cancelled'] as $status) {
            [$trx, $detail] = $this->transaksiRenceng();
            $trx->forceFill(['status' => $status])->save();

            $this->retur($trx, $this->line($detail, 1))->assertJsonValidationErrors([
                'items' => 'Transaksi ini tidak berstatus selesai, tidak bisa diretur.',
            ]);

            $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Transaksi ini tidak berstatus selesai, tidak bisa diretur.');
        }
        $this->assertNothingCreated();
    }

    // -------------------------------------------------------------- idempotency

    public function test_kirim_ulang_dengan_kunci_sama_tidak_membuat_retur_ganda_walau_sisa_sudah_habis(): void
    {
        [$trx, $detail, $product] = $this->transaksiRenceng();
        $key = (string) Str::uuid();

        // Retur PENUH: setelahnya sisa = 0, jadi kalau jalur replay tidak ada,
        // request kedua akan gagal "melebihi sisa" alih-alih mengembalikan retur asli.
        $first = $this->retur($trx, $this->line($detail, 3), ['idempotency_key' => $key]);
        $return = SalesReturn::firstOrFail();
        // Flash session dipakai bersama antar request di test: cek pesan request
        // pertama SEBELUM request kedua menimpanya.
        $first->assertOk()->assertJsonPath('created', true)
            ->assertSessionHas('success', "Retur {$return->return_number} berhasil dicatat.");

        $second = $this->retur($trx, $this->line($detail, 3), ['idempotency_key' => $key]);
        $second->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('return_number', $return->return_number)
            ->assertJsonPath('message', "Retur {$return->return_number} sudah tercatat sebelumnya (tidak dibuat ganda).")
            ->assertSessionHas('success', "Retur {$return->return_number} sudah tercatat sebelumnya (tidak dibuat ganda).");

        $this->assertSame(1, SalesReturn::count());
        $this->assertSame(1, SalesReturnItem::count());
        $this->assertSame(1, StockMovement::where('type', 'return_in')->count());
        $this->assertEquals(136, $this->stockOf($product)); // naik SEKALI saja
    }

    public function test_kunci_yang_sama_untuk_transaksi_lain_ditolak_tanpa_membocorkan_data(): void
    {
        [$trx1, $detail1] = $this->transaksiRenceng();
        [$trx2, $detail2] = $this->transaksiRenceng();
        $key = (string) Str::uuid();

        $this->retur($trx1, $this->line($detail1, 1), ['idempotency_key' => $key]);

        $this->retur($trx2, $this->line($detail2, 1), ['idempotency_key' => $key])->assertJsonValidationErrors([
            'idempotency_key' => 'Kode retur ini sudah dipakai. Muat ulang halaman lalu coba lagi.',
        ]);
        $this->assertSame(1, SalesReturn::count());
    }

    public function test_kunci_yang_sama_dari_admin_lain_ditolak(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();
        $key = (string) Str::uuid();
        $adminLain = User::factory()->admin()->create();

        $this->retur($trx, $this->line($detail, 1), ['idempotency_key' => $key]);

        $this->retur($trx, $this->line($detail, 1), ['idempotency_key' => $key], $adminLain)->assertJsonValidationErrors([
            'idempotency_key' => 'Kode retur ini sudah dipakai. Muat ulang halaman lalu coba lagi.',
        ]);
        $this->assertSame(1, SalesReturn::count());
    }

    public function test_request_kembar_yang_menunggu_lock_mengembalikan_retur_pertama_bukan_error(): void
    {
        // Simulasi race deterministik: tepat saat baris transaksi dikunci (retrieval
        // ke-2: ke-1 adalah route-model-binding), "request lain" sudah commit retur
        // PENUH dengan kunci yang sama. Cek replay pasca-lock harus mengembalikannya.
        // Tanpa cek itu, hitungan sisa (kini 0) menolak dengan "melebihi sisa".
        // Catatan: ini membuktikan LOGIKA lapis pasca-lock; serialisasi lock sungguhan
        // di MySQL tetap belum teruji di sandbox (QA-014).
        [$trx, $detail, $product] = $this->transaksiRenceng();
        $key = (string) Str::uuid();
        $kembar = null;
        $n = 0;

        Transaction::retrieved(function () use (&$n, &$kembar, $trx, $detail, $key) {
            if (++$n === 2) {
                $kembar = SalesReturn::forceCreate([
                    'return_number' => 'RTN-KEMBAR', 'idempotency_key' => $key,
                    'transaction_id' => $trx->id, 'user_id' => $this->admin->id,
                    'refund_method' => 'cash', 'total_refund' => 33000, 'reason' => 'kembar',
                ]);
                SalesReturnItem::forceCreate([
                    'sales_return_id' => $kembar->id, 'transaction_detail_id' => $detail->id,
                    'product_id' => $detail->product_id, 'product_name' => $detail->product_name,
                    'unit_name' => 'renceng', 'unit_conversion' => 12, 'quantity' => 3,
                    'condition' => 'damaged', 'refund_amount' => 33000,
                ]);
            }
        });

        $this->retur($trx, $this->line($detail, 3), ['idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('return_number', 'RTN-KEMBAR')
            ->assertJsonPath('message', 'Retur RTN-KEMBAR sudah tercatat sebelumnya (tidak dibuat ganda).');

        $this->assertSame(1, SalesReturn::count());
        $this->assertSame(0, StockMovement::where('type', 'return_in')->count());
        $this->assertEquals(100, $this->stockOf($product));

        Transaction::flushEventListeners();
    }

    public function test_service_menolak_metode_refund_tidak_valid_walau_dipanggil_tanpa_http(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        try {
            app(SalesReturnService::class)->process(
                $trx, $this->line($detail, 1), 'kredit', 'x', (string) Str::uuid(), $this->admin->id
            );
            $this->fail('Seharusnya melempar ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['refund_method' => ['Metode refund tidak valid.']], $e->errors());
        }
        $this->assertNothingCreated();
    }

    // ------------------------------------------------------------------ penomoran

    public function test_nomor_retur_berurutan_per_hari_dan_reset_di_hari_berikutnya(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 1));
        $this->retur($trx, $this->line($detail, 1));
        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00'));
        $this->retur($trx, $this->line($detail, 1));

        $this->assertSame(
            ['RTN-20261014-0001', 'RTN-20261014-0002', 'RTN-20261015-0001'],
            SalesReturn::orderBy('id')->pluck('return_number')->all()
        );
    }

    // ------------------------------------------------------------------ atomisitas

    public function test_kegagalan_di_tengah_proses_membatalkan_seluruh_retur(): void
    {
        $p1 = $this->makeProduct(['stock' => 100, 'average_cost' => 800]);
        $p2 = $this->makeProduct(['stock' => 100, 'average_cost' => 800]);
        $trx = $this->makeTransaction([], [
            ['product_id' => $p1->id, 'product_name' => 'A', 'unit_name' => 'sachet', 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
            ['product_id' => $p2->id, 'product_name' => 'B', 'unit_name' => 'sachet', 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
        ]);
        [$d1, $d2] = $trx->details()->orderBy('id')->get()->all();

        // Simulasikan DB gagal saat menaikkan stok produk ke-2 (SETELAH produk ke-1 berhasil).
        Product::saving(function (Product $p) use ($p2) {
            if ($p->id === $p2->id && $p->isDirty('stock')) {
                throw new \RuntimeException('simulasi gagal');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->retur($trx, [
                $d1->id => ['qty' => '1', 'condition' => 'resellable'],
                $d2->id => ['qty' => '1', 'condition' => 'resellable'],
            ]);
            $this->fail('Seharusnya melempar exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulasi gagal', $e->getMessage());
        }

        $this->assertNothingCreated();
        $this->assertEquals(100, $this->stockOf($p1), 'Stok produk ke-1 harus ikut ter-rollback');
        $this->assertEquals(100, $this->stockOf($p2));
    }

    // ------------------------------------------------------------------ halaman

    public function test_form_modal_menampilkan_baris_sisa_dan_atribut_untuk_js(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $html = $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))
            ->assertOk()->json('html');

        $this->assertStringContainsString($detail->product_name, $html);
        $this->assertStringContainsString('Sisa bisa diretur', $html);
        $this->assertStringContainsString(route('kasir.riwayat.retur.store', $trx), $html);
        // Atribut yang dibaca retur-modal.js untuk estimasi: 3 renceng terjual, subtotal 33.000.
        $this->assertStringContainsString('data-sold="3000"', $html);
        $this->assertStringContainsString('data-remaining="3000"', $html);
        $this->assertStringContainsString('data-subtotal="33000"', $html);
        $this->assertStringContainsString('data-remaining-refund="33000"', $html);
        $this->assertStringContainsString("name=\"items[{$detail->id}][qty]\"", $html);
        // Kunci idempotensi UUID baru di tiap pembukaan form.
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="[0-9a-f-]{36}"/', $html);
    }

    public function test_kunci_idempotensi_unik_di_setiap_pembukaan_form(): void
    {
        // Kunci yang sama di dua pembukaan form akan membuat retur KEDUA yang sah
        // ditolak sebagai "kembar" — jadi harus baru tiap form dibuka.
        [$trx] = $this->transaksiRenceng();
        $ambil = fn () => preg_match(
            '/name="idempotency_key" value="([0-9a-f-]{36})"/',
            $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))->json('html'),
            $m
        ) ? $m[1] : null;

        $a = $ambil();
        $b = $ambil();

        $this->assertNotNull($a);
        $this->assertNotSame($a, $b);
    }

    public function test_form_modal_menampilkan_sisa_setelah_retur_sebagian_dan_pesan_bila_penuh(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();

        $this->retur($trx, $this->line($detail, 1));
        $html = $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))->json('html');
        $this->assertStringContainsString('data-remaining="2000"', $html);
        $this->assertStringContainsString('data-remaining-refund="22000"', $html);

        $this->retur($trx, $this->line($detail, 2));
        $html = $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $trx))->json('html');
        $this->assertStringContainsString('Semua barang pada transaksi ini sudah diretur penuh', $html);
        $this->assertStringNotContainsString('data-retur-submit', $html);
    }

    public function test_form_modal_produk_curah_mengizinkan_langkah_pecahan(): void
    {
        $product = $this->makeProduct(
            ['tracking_mode' => 'weight', 'allow_fractional_sale' => true, 'stock' => 10],
            [['unit_name' => 'kg', 'conversion_to_base' => 1, 'selling_price' => 20000, 'is_base_unit' => true]]
        );
        $curah = $this->makeTransaction([], [[
            'product_id' => $product->id, 'product_name' => 'Curah', 'unit_name' => 'kg',
            'unit_conversion' => 1, 'price' => 20000, 'quantity' => 2.5, 'subtotal' => 50000,
        ]]);
        [$utuh] = $this->transaksiRenceng();

        $htmlCurah = $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $curah))->json('html');
        $htmlUtuh = $this->actingAs($this->admin)->getJson(route('kasir.riwayat.retur.form', $utuh))->json('html');

        $this->assertStringContainsString('step="0.001"', $htmlCurah);
        $this->assertStringContainsString('max="2.5"', $htmlCurah);
        $this->assertStringContainsString('step="1"', $htmlUtuh);
        $this->assertStringNotContainsString('step="0.001"', $htmlUtuh);
    }

    public function test_riwayat_menampilkan_tombol_retur_modal_dan_badge_status(): void
    {
        $kasir = User::factory()->kasir()->create();
        [$trx, $detail] = $this->transaksiRenceng();
        $trx->forceFill(['user_id' => $kasir->id])->save();
        $formUrl = route('kasir.riwayat.retur.form', $trx);

        // Belum ada retur: tombol ada (desktop + kartu mobile), tanpa badge, cangkang modal tersaji.
        $res = $this->actingAs($kasir)->get(route('kasir.riwayat'))->assertOk();
        $this->assertSame(2, substr_count($res->getContent(), 'data-retur-open="' . $formUrl . '"'));
        $res->assertSee('data-retur-invoice="' . $trx->invoice_number . '"', false)
            ->assertSee('id="modal-retur"', false)
            ->assertDontSee('Diretur sebagian')->assertDontSee('Diretur penuh');

        // Retur sebagian: badge kuning, tombol tetap ada.
        $this->retur($trx, $this->line($detail, 1), [], $kasir);
        $res = $this->actingAs($kasir)->get(route('kasir.riwayat'))->assertOk();
        $res->assertSee('Diretur sebagian');
        $this->assertSame(2, substr_count($res->getContent(), 'data-retur-open="' . $formUrl . '"'));

        // Retur penuh: badge abu-abu, tombol hilang.
        $this->retur($trx, $this->line($detail, 2), [], $kasir);
        $res = $this->actingAs($kasir)->get(route('kasir.riwayat'))->assertOk();
        $res->assertSee('Diretur penuh')->assertDontSee('Diretur sebagian');
        $this->assertSame(0, substr_count($res->getContent(), 'data-retur-open='));
    }

    public function test_riwayat_kasir_tidak_menampilkan_tombol_retur_untuk_transaksi_kasir_lain(): void
    {
        $kasir = User::factory()->kasir()->create();
        [$lain] = $this->transaksiRenceng();

        $this->actingAs($kasir)->get(route('kasir.riwayat'))->assertOk()
            ->assertDontSee($lain->invoice_number)
            ->assertDontSee(route('kasir.riwayat.retur.form', $lain), false);
    }

    public function test_semua_halaman_retur_render_tanpa_lazy_loading_dengan_banyak_data(): void
    {
        // Model::preventLazyLoading() aktif di environment testing, tapi HANYA melempar
        // kalau hasil query berisi >1 model — jadi tiap daftar di sini sengaja berisi >=2.
        $kasir = User::factory()->kasir()->create();
        $dibuat = [];
        for ($i = 0; $i < 2; $i++) {
            [$trx, $detail] = $this->transaksiRenceng();
            $trx->forceFill(['user_id' => $kasir->id])->save();
            $this->retur($trx, $this->line($detail, 1), [], $kasir)->assertOk();
            $dibuat[] = $trx;
        }

        // Transaksi 3 baris (>1 detail) untuk form & detail retur.
        $p = [$this->makeProduct(['stock' => 10]), $this->makeProduct(['stock' => 10])];
        $banyak = $this->makeTransaction(['user_id' => $kasir->id], [
            ['product_id' => $p[0]->id, 'product_name' => 'A', 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
            ['product_id' => $p[1]->id, 'product_name' => 'B', 'unit_conversion' => 1, 'price' => 1000, 'quantity' => 2, 'subtotal' => 2000],
        ]);
        [$d1, $d2] = $banyak->details()->orderBy('id')->get()->all();
        $this->retur($banyak, [
            $d1->id => ['qty' => '1', 'condition' => 'resellable'],
            $d2->id => ['qty' => '1', 'condition' => 'damaged'],
        ], [], $kasir)->assertOk();

        $this->actingAs($kasir)->get(route('kasir.riwayat'))->assertOk();
        $this->actingAs($kasir)->getJson(route('kasir.riwayat.retur.form', $banyak))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.retur-penjualan.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-penjualan.index', ['cari' => 'TST-INV']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.retur-penjualan.show', SalesReturn::latest('id')->first()))->assertOk();
        $this->actingAs($this->admin)->get(route('gudang.stok.show', $p[0]))->assertOk();
    }

    public function test_halaman_admin_memakai_modal_yang_sama_untuk_proses_retur(): void
    {
        [$trx] = $this->transaksiRenceng();

        $this->actingAs($this->admin)
            ->get(route('admin.retur-penjualan.index', ['cari' => $trx->invoice_number]))
            ->assertOk()
            ->assertSee('data-retur-open="' . route('kasir.riwayat.retur.form', $trx) . '"', false)
            ->assertSee('id="modal-retur"', false);
    }

    public function test_pencarian_invoice_menampilkan_status_retur_dan_menyembunyikan_tombol_bila_penuh(): void
    {
        [$trx, $detail] = $this->transaksiRenceng();
        $url = route('admin.retur-penjualan.index', ['cari' => $trx->invoice_number]);

        $this->actingAs($this->admin)->get($url)
            ->assertOk()->assertSee('Proses Retur')->assertDontSee('Sudah diretur');

        $this->retur($trx, $this->line($detail, 1));
        $this->actingAs($this->admin)->get($url)
            ->assertOk()->assertSee('Sudah diretur sebagian')->assertSee('Proses Retur');

        $this->retur($trx, $this->line($detail, 2));
        $this->actingAs($this->admin)->get($url)
            ->assertOk()->assertSee('Sudah diretur penuh')->assertDontSee('Proses Retur')
            ->assertSee('Tidak ada sisa untuk diretur');
    }

    public function test_hasil_pencarian_dibatasi_10_transaksi(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->transaksiRenceng();
        }

        $html = $this->actingAs($this->admin)
            ->get(route('admin.retur-penjualan.index', ['cari' => 'TST-INV']))
            ->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'Proses Retur'));
    }

    public function test_pencarian_hanya_transaksi_selesai_dan_wildcard_tidak_dianggap_pola(): void
    {
        [$trx] = $this->transaksiRenceng();
        [$batal] = $this->transaksiRenceng();
        $batal->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.retur-penjualan.index', ['cari' => $batal->invoice_number]))
            ->assertOk()->assertSee('Tidak ada transaksi selesai');

        // "_" dan "%" dicari apa adanya (bukan wildcard LIKE): tidak ada invoice yang memuatnya.
        foreach (['_', '%'] as $needle) {
            $this->actingAs($this->admin)
                ->get(route('admin.retur-penjualan.index', ['cari' => $needle]))
                ->assertOk()->assertSee('Tidak ada transaksi selesai')->assertDontSee($trx->invoice_number);
        }
    }

    public function test_halaman_detail_retur_dan_riwayat_stok_menampilkan_retur(): void
    {
        [$trx, $detail, $product] = $this->transaksiRenceng();
        $this->retur($trx, $this->line($detail, 1), ['reason' => 'Penyok parah']);
        $return = SalesReturn::firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.retur-penjualan.show', $return))
            ->assertOk()->assertSee('RTN-20261014-0001')->assertSee('Penyok parah')
            ->assertSee('Layak jual (masuk stok)')->assertSee('Rp 11.000');

        $this->actingAs($this->admin)->get(route('admin.retur-penjualan.index'))
            ->assertOk()->assertSee('RTN-20261014-0001');

        $this->actingAs($this->admin)->get(route('gudang.stok.show', $product))
            ->assertOk()->assertSee('Retur Pelanggan');
    }
}