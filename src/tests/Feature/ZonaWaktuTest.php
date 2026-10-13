<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-008 — satu zona waktu bisnis (WIB) di penyimpanan, tampilan, batas hari/bulan
 * dashboard & laporan, dan tanggal pada nomor dokumen.
 *
 * Kasus yang dulu salah (aplikasi UTC, toko WIB): pukul 06:30 WIB tanggal 2 Okt = 23:30 UTC
 * tanggal 1 Okt, sehingga "hari ini" masih 1 Okt, jam tertulis 23:30, dan nomor
 * dokumen berawalan tanggal kemarin. Hari baru baru terbit pukul 07:00 WIB.
 */
class ZonaWaktuTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    /** Bekukan waktu pada jam dinding WIB (setTestNow mengabaikan argumen zona waktu kedua). */
    private function pada(string $wib): void
    {
        Carbon::setTestNow(Carbon::parse($wib, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Konfigurasi
    // ------------------------------------------------------------------

    public function test_zona_waktu_bisnis_adalah_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
        $this->assertSame('+07:00', Carbon::now()->format('P'));
    }

    public function test_zona_waktu_sesi_mysql_sama_dengan_zona_waktu_aplikasi(): void
    {
        // Bila keduanya berbeda, kolom TIMESTAMP bergeser diam-diam di database.
        $appOffset = Carbon::now(config('app.timezone'))->format('P');

        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame(
                $appOffset,
                config("database.connections.{$connection}.timezone"),
                "DB_TIMEZONE koneksi {$connection} harus sama dengan offset app.timezone"
            );
        }
    }

    // ------------------------------------------------------------------
    // Kasus inti: 06:30 WIB
    // ------------------------------------------------------------------

    public function test_pukul_0630_wib_masuk_tanggal_wib_bukan_tanggal_utc(): void
    {
        $this->pada('2026-10-02 06:30:00');

        $this->assertSame('2026-10-02', Carbon::today()->toDateString());
        $this->assertSame('06:30', now()->format('H:i'));
        $this->assertSame('2026-10-01', now('UTC')->toDateString(), 'prasyarat: di UTC saat itu masih kemarin');
    }

    public function test_checkout_pukul_0630_wib_tercatat_dengan_tanggal_jam_dan_nomor_wib(): void
    {
        $this->pada('2026-10-02 06:30:00');
        $kasir = User::factory()->kasir()->create();
        $product = $this->makeProduct(['stock' => 100, 'average_cost' => 700]);
        $unit = $product->units->firstWhere('unit_name', 'sachet');

        $this->actingAs($kasir)->postJson(route('kasir.pos.checkout'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'qty' => 2]],
            'payment_method' => 'cash',
            'paid_amount' => 1000000,
        ])->assertSuccessful();

        $trx = Transaction::firstOrFail();
        $this->assertMatchesRegularExpression('/^INV-20261002-\d{4}$/', $trx->invoice_number);
        $this->assertSame('2026-10-02 06:30:00', DB::table('transactions')->where('id', $trx->id)->value('created_at'));
        $this->assertSame('06:30', $trx->created_at->format('H:i'), 'jam di struk/riwayat = jam dinding WIB');

        // Dan benar-benar dihitung sebagai "hari ini" di dashboard Admin.
        $kpi = $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->viewData('kpi');
        $this->assertSame(1, $kpi['jumlah_transaksi']);
    }

    // ------------------------------------------------------------------
    // Nomor dokumen
    // ------------------------------------------------------------------

    public function test_semua_nomor_dokumen_memakai_tanggal_wib(): void
    {
        $this->pada('2026-10-02 06:30:00');

        $this->assertMatchesRegularExpression('/^INV-20261002-\d{4}$/', Transaction::generateInvoiceNumber());
        $this->assertMatchesRegularExpression('/^PO-20261002-\d{4}$/', PurchaseOrder::generatePoNumber());
        $this->assertMatchesRegularExpression('/^RTN-20261002-\d{4}$/', SalesReturn::generateReturnNumber());
        $this->assertMatchesRegularExpression('/^RTP-20261002-\d{4}$/', PurchaseReturn::generateReturnNumber());
    }

    public function test_tanggal_nomor_berganti_tepat_tengah_malam_wib_bukan_pukul_0700(): void
    {
        foreach ([
            '2026-10-01 23:59:59' => '20261001',
            '2026-10-02 00:00:00' => '20261002',
            '2026-10-02 06:59:59' => '20261002',   // sebelum tengah malam UTC: tetap tanggal 2
            '2026-10-02 07:00:00' => '20261002',   // tengah malam UTC bukan batas hari
            '2026-10-02 23:59:59' => '20261002',
            '2026-10-03 00:00:00' => '20261003',
        ] as $wib => $tanggal) {
            $this->pada($wib);

            $this->assertStringStartsWith("INV-{$tanggal}-", Transaction::generateInvoiceNumber(), "pada {$wib} WIB");
            $this->assertStringStartsWith("RTN-{$tanggal}-", SalesReturn::generateReturnNumber(), "pada {$wib} WIB");
        }
    }

    // ------------------------------------------------------------------
    // Batas hari dashboard dan laporan
    // ------------------------------------------------------------------

    public function test_kpi_hari_ini_mengikuti_hari_wib_bukan_hari_utc(): void
    {
        $this->pada('2026-10-02 06:30:00');
        foreach ([
            '2026-10-01 23:59:59' => 1000,    // kemarin (WIB)         -> TIDAK dihitung
            '2026-10-02 00:00:00' => 2000,    // awal hari WIB         -> dihitung
            '2026-10-02 06:30:00' => 4000,    // 06:30 WIB (di UTC kemarin!) -> dihitung
            '2026-10-02 23:59:59' => 8000,    // akhir hari WIB        -> dihitung
            '2026-10-03 00:00:00' => 16000,   // besok (WIB)           -> TIDAK dihitung
        ] as $at => $total) {
            $this->makeTransaction(['grand_total' => $total, 'created_at' => $at]);
        }

        $kpi = $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))->assertOk()->viewData('kpi');

        $this->assertSame(3, $kpi['jumlah_transaksi']);
        $this->assertSame(14000, $kpi['omzet_kotor']);
    }

    public function test_hari_dashboard_baru_terbit_tengah_malam_wib_bukan_pukul_0700(): void
    {
        $this->makeTransaction(['grand_total' => 5000, 'created_at' => '2026-10-02 00:30:00']);
        $admin = User::factory()->admin()->create();

        $this->pada('2026-10-01 23:59:59');
        $this->assertSame(0, $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('kpi')['jumlah_transaksi'], 'masih 1 Okt');

        $this->pada('2026-10-02 00:30:00');
        $this->assertSame(1, $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('kpi')['jumlah_transaksi'], 'sudah 2 Okt, jauh sebelum 07:00');
    }

    public function test_laporan_penjualan_satu_hari_memuat_awal_dan_akhir_hari_wib(): void
    {
        foreach ([
            '2026-10-01 23:59:59' => 1000,   // di luar
            '2026-10-02 00:00:00' => 2000,
            '2026-10-02 06:30:00' => 4000,
            '2026-10-02 23:59:59' => 8000,
            '2026-10-03 00:00:00' => 16000,  // di luar
        ] as $at => $total) {
            $this->makeTransaction(['grand_total' => $total, 'created_at' => $at]);
        }

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.laporan.penjualan', ['from' => '2026-10-02', 'to' => '2026-10-02']))
            ->assertOk()
            ->assertViewHas('jumlah_transaksi', 3)
            ->assertViewHas('total_omzet', 14000);
    }
}