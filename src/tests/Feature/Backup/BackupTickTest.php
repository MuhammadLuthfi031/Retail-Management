<?php

namespace Tests\Feature\Backup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;

/** backup:tick (dipanggil penjadwal tiap menit) dan definisi jadwalnya. */
class BackupTickTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackupSandbox();
        parent::tearDown();
    }

    public function test_tanpa_permintaan_hanya_mencatat_detak(): void
    {
        $this->artisan('backup:tick')->assertExitCode(0);

        $this->assertTrue($this->backups->schedulerAlive());
        $this->assertSame([], $this->backups->list());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_permintaan_manual_diproses_menjadi_backup_berlabel_manual(): void
    {
        $this->backups->requestRun(3);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Backup manual selesai')
            ->assertExitCode(0);

        $list = $this->backups->list();
        $this->assertCount(1, $list);
        $this->assertSame('manual', $list[0]['label']);
        $this->assertNull($this->backups->pendingRequest(), 'permintaan sudah dikonsumsi');
    }

    public function test_permintaan_diproses_hanya_sekali_walau_tick_berikutnya_berjalan(): void
    {
        $this->backups->requestRun(3);

        $this->artisan('backup:tick')->assertExitCode(0);
        $this->artisan('backup:tick')->assertExitCode(0);

        $this->assertCount(1, $this->backups->list());
        $this->assertSame(['dump'], $this->dumper->calls);
    }

    public function test_permintaan_gagal_tidak_diulang_tiap_menit_dan_kegagalan_tercatat(): void
    {
        $this->backups->requestRun(3);
        $this->dumper->failDump = true;

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Backup manual GAGAL')
            ->assertExitCode(1);
        $this->artisan('backup:tick')->assertExitCode(0);

        $this->assertSame(['dump'], $this->dumper->calls, 'tidak ada percobaan ulang otomatis');
        $this->assertStringContainsString('Dump database gagal', $this->backups->status()['last_error']);
    }

    public function test_folder_tidak_bisa_ditulis_dilaporkan_bukan_diam(): void
    {
        config(['backup.path' => '/proc/tidak-bisa-ditulis']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Folder backup bermasalah')
            ->assertExitCode(1);
    }

    public function test_jadwal_harian_dan_tiap_menit_terdaftar_dengan_zona_waktu_wib(): void
    {
        $events = collect(app(Schedule::class)->events());

        $daily = $events->first(fn ($e) => str_contains($e->command, 'backup:run'));
        $tick = $events->first(fn ($e) => str_contains($e->command, 'backup:tick'));

        $this->assertNotNull($daily, 'backup:run harus terjadwal');
        $this->assertSame('0 23 * * *', $daily->expression);
        $this->assertSame('Asia/Jakarta', (string) $daily->timezone);
        $this->assertTrue($daily->withoutOverlapping);

        $this->assertNotNull($tick, 'backup:tick harus terjadwal');
        $this->assertSame('* * * * *', $tick->expression);
        $this->assertTrue($tick->withoutOverlapping);
    }
}