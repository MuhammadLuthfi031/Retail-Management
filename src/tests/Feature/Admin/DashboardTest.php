<?php

namespace Tests\Feature\Admin;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cakupan test ini SENGAJA sempit — cuma menguji QA-006 (whereDate() ->
 * whereBetween() di grafikPenjualan/grafikBarangMasuk/produkTerlaris), bukan
 * korektivitas menyeluruh Dashboard.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regresi utama QA-006: mengganti whereDate() jadi whereBetween() HANYA
     * aman kalau $to juga endOfDay() (bukan jam 00:00:00) — kalau tidak,
     * transaksi hari ini akan hilang dari grafik karena created_at (siang/sore
     * hari ini) jatuh SETELAH $to (00:00:00 hari ini). Test ini memastikan
     * skenario itu tidak terulang.
     */
    public function test_todays_transaction_appears_in_sales_chart(): void
    {
        $admin = User::factory()->admin()->create();
        $kasir = User::factory()->kasir()->create();

        Transaction::create([
            'invoice_number' => 'TEST-' . uniqid(),
            'user_id' => $kasir->id,
            'total_amount' => 75000,
            'discount_amount' => 0,
            'grand_total' => 75000,
            'paid_amount' => 75000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]); // created_at otomatis "sekarang" (hari ini)

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewHas('grafikPenjualan', function (array $grafik) {
            // Elemen TERAKHIR = hari ini (buildDailySeries mengisi berurutan
            // dari $from ke $to). Harus > 0 — sebelum QA-006 diperbaiki dengan
            // benar, ini akan 0 walau transaksinya benar-benar ada.
            return end($grafik['values']) > 0;
        });
    }
}