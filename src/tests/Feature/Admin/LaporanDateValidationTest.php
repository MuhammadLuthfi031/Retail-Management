<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cakupan test ini SENGAJA sempit — cuma menguji QA-003 (validasi input
 * tanggal filter Laporan sebelum di-parse), BUKAN korektivitas angka hasil
 * laporan (itu QA-004, belum digarap, masih tercatat sebagai temuan terpisah
 * di dokumen QA).
 */
class LaporanDateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_from_date_returns_validation_error_not_500(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.laporan.penjualan', ['from' => 'tanggal-ngaco']));

        $response->assertSessionHasErrors('from');
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_invalid_to_date_returns_validation_error_not_500(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.laporan.laba-rugi', ['to' => '31-13-2026']));

        $response->assertSessionHasErrors('to');
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_invalid_date_on_pdf_export_also_returns_validation_error_not_500(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.laporan.stok.pdf', ['from' => 'bukan-tanggal']));

        $response->assertSessionHasErrors('from');
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_valid_date_range_still_works_normally(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.laporan.penjualan', [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ]));

        $response->assertOk();
        $response->assertSessionHasNoErrors();
    }
}