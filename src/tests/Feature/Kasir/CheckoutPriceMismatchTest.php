<?php

namespace Tests\Feature\Kasir;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * Deteksi harga usang di layar kasir: kalau Admin/Gudang mengubah harga_jual
 * SETELAH katalog dimuat ke browser kasir, expected_price yang dikirim client
 * (harga yang tampil di layar) tidak lagi sama dengan harga_jual di database.
 * checkout() harus MENOLAK (409) dan membatalkan SELURUH transaksi — bukan
 * memproses sebagian dengan harga campuran.
 */
class CheckoutPriceMismatchTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $kasir;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->kasir()->create();
        $this->product = $this->makeProduct(['stock' => 100]); // renceng Rp 11.000, sachet Rp 1.000
    }

    private function checkout(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->kasir)->postJson(route('kasir.pos.checkout'), array_merge([
            'items' => $items,
            'payment_method' => 'cash',
            'paid_amount' => 1000000,
        ], $extra));
    }

    private function assertNothingWasSaved(): void
    {
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, TransactionDetail::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stale_price_is_rejected_with_409_and_current_price(): void
    {
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        $response = $this->checkout([[
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'qty' => 1,
            'expected_price' => 9000, // kasir mengira harga masih 9.000, padahal DB-nya 11.000
        ]]);

        $response->assertStatus(409)
            ->assertJsonPath('mismatches.0.product_id', $this->product->id)
            ->assertJsonPath('mismatches.0.unit_id', $unit->id)
            ->assertJsonPath('mismatches.0.expected_price', 9000)
            ->assertJsonPath('mismatches.0.current_price', 11000)
            ->assertJsonPath('mismatches.0.product_name', $this->product->name)
            ->assertJsonPath('mismatches.0.unit_name', 'renceng');

        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product)); // stok TIDAK ikut terpotong
    }

    public function test_matching_price_proceeds_normally(): void
    {
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        $this->checkout([[
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'qty' => 1,
            'expected_price' => 11000, // sama persis dengan DB
        ]])->assertCreated()->assertJsonPath('data.grand_total', 11000);

        $this->assertSame(1, Transaction::count());
    }

    public function test_missing_expected_price_skips_the_check_for_backward_compatibility(): void
    {
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        // Client lama yang belum mengirim expected_price sama sekali (field tidak ada).
        $this->checkout([[
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'qty' => 1,
        ]])->assertCreated();
    }

    public function test_one_stale_line_rolls_back_the_whole_cart_even_when_other_lines_match(): void
    {
        $second = $this->makeProduct(['stock' => 50]);
        $renceng1 = $this->product->units->firstWhere('unit_name', 'renceng');
        $renceng2 = $second->units->firstWhere('unit_name', 'renceng');

        $response = $this->checkout([
            ['product_id' => $this->product->id, 'unit_id' => $renceng1->id, 'qty' => 1, 'expected_price' => 11000], // cocok
            ['product_id' => $second->id, 'unit_id' => $renceng2->id, 'qty' => 1, 'expected_price' => 5000], // usang (DB: 11000)
        ]);

        $response->assertStatus(409);
        $this->assertCount(1, $response->json('mismatches'));

        $this->assertNothingWasSaved();
        $this->assertEquals(100.0, $this->stockOf($this->product));
        $this->assertEquals(50.0, $this->stockOf($second));
    }

    public function test_multiple_stale_lines_are_all_reported_in_one_response(): void
    {
        $second = $this->makeProduct(['stock' => 50]);
        $r1 = $this->product->units->firstWhere('unit_name', 'renceng');
        $r2 = $second->units->firstWhere('unit_name', 'renceng');

        $response = $this->checkout([
            ['product_id' => $this->product->id, 'unit_id' => $r1->id, 'qty' => 1, 'expected_price' => 1],
            ['product_id' => $second->id, 'unit_id' => $r2->id, 'qty' => 1, 'expected_price' => 2],
        ]);

        $response->assertStatus(409);
        $this->assertCount(2, $response->json('mismatches'));
        $this->assertNothingWasSaved();
    }

    public function test_stale_price_check_ignores_client_sent_totals(): void
    {
        // Sekalian pastikan pengecekan harga tidak bisa "dilewati" dengan mengirim
        // total/subtotal palsu — checkout tetap murni menolak berdasar harga per unit.
        $unit = $this->product->units->firstWhere('unit_name', 'renceng');

        $this->checkout([[
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'qty' => 1,
            'expected_price' => 1,
            'price' => 11000,
            'subtotal' => 11000,
        ]])->assertStatus(409);

        $this->assertNothingWasSaved();
    }
}