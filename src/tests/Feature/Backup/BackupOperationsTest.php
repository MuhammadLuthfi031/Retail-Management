<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;

/** Status berjalan, permintaan manual, detak penjadwal, dan daftar masalah (issues). */
class BackupOperationsTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupSandbox();
        config(['backup.disk_warn_mb' => 0]); // jangan bergantung ruang disk mesin penguji
    }

    protected function tearDown(): void
    {
        $this->tearDownBackupSandbox();
        parent::tearDown();
    }

    private function nowIsWib(string $wallClock): void
    {
        Carbon::setTestNow(Carbon::parse($wallClock, 'Asia/Jakarta'));
    }

    private function holdLock()
    {
        $h = fopen($this->backupDir.'/.lock', 'c');
        flock($h, LOCK_EX);

        return $h;
    }

    // ---- isRunning ----

    public function test_is_running_mengikuti_kunci(): void
    {
        $this->assertFalse($this->backups->isRunning());

        $h = $this->holdLock();
        $this->assertTrue($this->backups->isRunning());

        flock($h, LOCK_UN);
        fclose($h);
        $this->assertFalse($this->backups->isRunning());
    }

    public function test_is_running_false_dan_tidak_error_bila_folder_belum_ada(): void
    {
        config(['backup.path' => $this->sandbox.'/belum-ada']);

        $this->assertFalse($this->backups->isRunning());
        $this->assertDirectoryDoesNotExist($this->sandbox.'/belum-ada', 'membaca status tidak boleh membuat folder');
    }

    // ---- permintaan manual ----

    public function test_request_run_membuat_permintaan_dan_menolak_duplikat(): void
    {
        $this->nowIsWib('2026-10-10 09:00:00');

        $this->assertSame(BackupService::REQUEST_QUEUED, $this->backups->requestRun(7));
        $this->assertSame(BackupService::REQUEST_ALREADY_PENDING, $this->backups->requestRun(8));

        $pending = $this->backups->pendingRequest();
        $this->assertSame(7, $pending['requested_by'], 'permintaan pertama yang tercatat');
        $this->assertSame('2026-10-10T09:00:00+07:00', $pending['requested_at']);
    }

    public function test_request_run_ditolak_saat_backup_sedang_berjalan(): void
    {
        $h = $this->holdLock();

        $this->assertSame(BackupService::REQUEST_RUNNING, $this->backups->requestRun(1));
        $this->assertNull($this->backups->pendingRequest(), 'tidak ada antrean yang tersisa');

        flock($h, LOCK_UN);
        fclose($h);
    }

    public function test_consume_request_mengambil_sekali_lalu_habis(): void
    {
        $this->backups->requestRun(5);

        $first = $this->backups->consumeRequest();
        $second = $this->backups->consumeRequest();

        $this->assertSame(5, $first['requested_by']);
        $this->assertNull($second);
        $this->assertNull($this->backups->pendingRequest());
        $this->assertSame([], glob($this->backupDir.'/run-requested*'), 'tidak ada file sisa');
    }

    public function test_file_penanda_tidak_muncul_di_daftar_backup(): void
    {
        $this->backups->requestRun(1);
        $this->backups->recordHeartbeat();

        $this->assertSame([], $this->backups->list());
    }

    // ---- detak ----

    public function test_detak_penjadwal_dianggap_mati_setelah_lima_menit(): void
    {
        $this->nowIsWib('2026-10-10 09:00:00');
        $this->assertFalse($this->backups->schedulerAlive(), 'belum pernah ada detak');
        $this->assertNull($this->backups->heartbeatAt());

        $this->backups->recordHeartbeat();
        $this->assertTrue($this->backups->schedulerAlive());

        $this->nowIsWib('2026-10-10 09:04:59');
        $this->assertTrue($this->backups->schedulerAlive());

        $this->nowIsWib('2026-10-10 09:05:01');
        $this->assertFalse($this->backups->schedulerAlive());
    }

    // ---- issues ----

    public function test_tanpa_backup_dan_tanpa_detak_dilaporkan_bermasalah_terparah_dulu(): void
    {
        $issues = $this->backups->issues();

        $this->assertSame('danger', $issues[0]['level']);
        $this->assertStringContainsString('Belum ada backup', $issues[0]['message']);
        $this->assertSame('warning', end($issues)['level']);
        $this->assertStringContainsString('Penjadwal tidak aktif', end($issues)['message']);
    }

    public function test_kondisi_sehat_tidak_menghasilkan_masalah(): void
    {
        $this->backups->create();
        $this->backups->recordHeartbeat();

        $this->assertSame([], $this->backups->issues());
    }

    public function test_backup_terlalu_lama_dilaporkan_dalam_bahasa_indonesia(): void
    {
        $this->nowIsWib('2026-10-10 12:00:00');
        $this->backups->create();
        $this->nowIsWib('2026-10-12 12:00:00');
        $this->backups->recordHeartbeat();

        $issues = $this->backups->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('lebih dari 26 jam', $issues[0]['message']);
        $this->assertStringContainsString('2 hari yang lalu', $issues[0]['message']);
    }

    public function test_kegagalan_terakhir_dilaporkan_dan_hilang_setelah_sukses(): void
    {
        $this->backups->recordHeartbeat();
        $this->dumper->failDump = true;
        try {
            $this->backups->create();
        } catch (\Throwable) {
        }

        $messages = array_column($this->backups->issues(), 'message');
        $this->assertTrue((bool) array_filter($messages, fn ($m) => str_contains($m, 'GAGAL') && str_contains($m, 'simulasi kegagalan')));

        $this->dumper->failDump = false;
        $this->backups->create();
        $this->assertSame([], $this->backups->issues(), 'sukses menghapus catatan gagal');
    }

    public function test_disk_hampir_penuh_dilaporkan_sebagai_peringatan(): void
    {
        $this->backups->create();
        $this->backups->recordHeartbeat();
        config(['backup.disk_warn_mb' => 999_999_999]);

        $issues = $this->backups->issues();

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['level']);
        $this->assertStringContainsString('hampir habis', $issues[0]['message']);
    }

    public function test_peringatan_excel_pada_backup_terakhir_dilaporkan(): void
    {
        $this->app->instance(\App\Services\Backup\ExcelArchive::class, new class extends \App\Services\Backup\ExcelArchive {
            public function write(string $path, \DateTimeInterface $createdAt): array
            {
                throw new \RuntimeException('boom');
            }
        });
        $service = $this->app->make(BackupService::class);
        $service->create();
        $service->recordHeartbeat();

        $issues = $service->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('peringatan', $issues[0]['message']);
    }
}