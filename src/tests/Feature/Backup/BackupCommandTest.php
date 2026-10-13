<?php

namespace Tests\Feature\Backup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;

/** Perintah artisan backup:run / backup:list / backup:restore. */
class BackupCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupSandbox;

    private const CONFIRM = 'Ketik PULIHKAN (huruf besar) untuk melanjutkan';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupSandbox();
    }

    protected function tearDown(): void
    {
        // Jaring pengaman: mode maintenance yang tersangkut akan membuat SEMUA test HTTP berikutnya 503.
        $this->callArtisanUp();
        $this->tearDownBackupSandbox();
        parent::tearDown();
    }

    private function callArtisanUp(): void
    {
        \Illuminate\Support\Facades\Artisan::call('up');
    }

    private function makeBackup(): array
    {
        return $this->backups->create();
    }

    // ---- backup:run ----

    public function test_run_sukses_membuat_backup_otomatis(): void
    {
        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup berhasil')
            ->assertExitCode(0);

        $this->assertCount(1, $this->backups->list());
        $this->assertSame('auto', $this->backups->list()[0]['label']);
    }

    public function test_run_manual_memberi_label_manual(): void
    {
        $this->artisan('backup:run', ['--manual' => true])->assertExitCode(0);

        $this->assertSame('manual', $this->backups->list()[0]['label']);
    }

    public function test_run_gagal_keluar_dengan_kode_1_dan_pesan_jelas(): void
    {
        $this->dumper->failDump = true;

        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup GAGAL: Dump database gagal')
            ->assertExitCode(1);

        $this->assertSame([], $this->backups->list());
    }

    // ---- backup:list ----

    public function test_list_kosong_menyarankan_menjalankan_backup(): void
    {
        $this->artisan('backup:list')
            ->expectsOutputToContain('Belum ada backup')
            ->expectsOutputToContain('PERINGATAN')
            ->assertExitCode(0);
    }

    public function test_list_menampilkan_backup_dan_status(): void
    {
        $info = $this->makeBackup();

        $this->artisan('backup:list')
            ->expectsOutputToContain($info['name'])
            ->expectsOutputToContain('Backup sukses terakhir')
            ->doesntExpectOutputToContain('PERINGATAN')
            ->assertExitCode(0);
    }

    // ---- backup:restore ----

    public function test_restore_dibatalkan_bila_kata_konfirmasi_salah(): void
    {
        $info = $this->makeBackup();

        $this->artisan('backup:restore', ['file' => $info['name']])
            ->expectsQuestion(self::CONFIRM, 'ya')
            ->expectsOutputToContain('Dibatalkan')
            ->assertExitCode(1);

        $this->assertNotContains('restore', $this->dumper->calls, 'tidak ada yang boleh diubah');
        $this->assertFalse($this->app->isDownForMaintenance());
    }

    public function test_restore_berjalan_bila_konfirmasi_benar_dan_maintenance_aktif_selama_proses(): void
    {
        $info = $this->makeBackup();
        $downDuringRestore = null;
        $this->dumper->onRestore[] = function () use (&$downDuringRestore) {
            $downDuringRestore = $this->app->isDownForMaintenance();
        };

        $this->artisan('backup:restore', ['file' => $info['name']])
            ->expectsQuestion(self::CONFIRM, 'PULIHKAN')
            ->expectsOutputToContain('Restore berhasil')
            ->assertExitCode(0);

        $this->assertTrue($downDuringRestore, 'web harus dikunci selama database diganti');
        $this->assertFalse($this->app->isDownForMaintenance(), 'maintenance harus dilepas setelah selesai');
        $this->assertContains('restore', $this->dumper->calls);
    }

    public function test_restore_gagal_tetap_melepas_maintenance(): void
    {
        $info = $this->makeBackup();
        $this->dumper->failRestoreCalls = [1];

        $this->artisan('backup:restore', ['file' => $info['name'], '--force' => true])
            ->expectsOutputToContain('Restore GAGAL')
            ->assertExitCode(1);

        $this->assertFalse($this->app->isDownForMaintenance(), 'maintenance tidak boleh tersangkut');
    }

    public function test_restore_force_melewati_konfirmasi(): void
    {
        $info = $this->makeBackup();

        $this->artisan('backup:restore', ['file' => $info['name'], '--force' => true])
            ->expectsOutputToContain('Restore berhasil')
            ->assertExitCode(0);
    }

    public function test_restore_non_interaktif_tanpa_force_ditolak(): void
    {
        $info = $this->makeBackup();

        $this->artisan('backup:restore', ['file' => $info['name'], '--no-interaction' => true])
            ->expectsOutputToContain('membutuhkan opsi --force')
            ->assertExitCode(1);

        $this->assertNotContains('restore', $this->dumper->calls);
    }

    public function test_restore_file_tidak_ditemukan(): void
    {
        $this->artisan('backup:restore', ['file' => 'backup-20200101-000000-auto.zip', '--force' => true])
            ->expectsOutputToContain('File backup tidak ditemukan')
            ->assertExitCode(1);
    }

    public function test_restore_menolak_nama_berbahaya_dan_file_bukan_zip(): void
    {
        file_put_contents($this->sandbox.'/rahasia.txt', 'x');

        $this->artisan('backup:restore', ['file' => '../rahasia.txt', '--force' => true])->assertExitCode(1);
        $this->artisan('backup:restore', ['file' => $this->sandbox.'/rahasia.txt', '--force' => true])->assertExitCode(1);
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_restore_menolak_zip_rusak_sebelum_konfirmasi(): void
    {
        file_put_contents($this->sandbox.'/rusak.zip', 'bukan zip');

        $this->artisan('backup:restore', ['file' => $this->sandbox.'/rusak.zip'])
            ->expectsOutputToContain('rusak atau bukan arsip zip')
            ->assertExitCode(1);
    }

    public function test_restore_menerima_path_lengkap_ke_file_di_luar_folder_backup(): void
    {
        $info = $this->makeBackup();
        $copy = $this->sandbox.'/dari-flashdisk.zip';
        copy($info['path'], $copy);

        $this->artisan('backup:restore', ['file' => $copy, '--force' => true])->assertExitCode(0);
    }

    public function test_restore_tidak_melepas_maintenance_yang_sudah_menyala_sebelumnya(): void
    {
        $info = $this->makeBackup();
        $this->artisan('down')->assertExitCode(0);

        $this->artisan('backup:restore', ['file' => $info['name'], '--force' => true])->assertExitCode(0);

        $this->assertTrue($this->app->isDownForMaintenance(), 'maintenance yang dinyalakan operator tidak boleh dimatikan oleh restore');
    }
}