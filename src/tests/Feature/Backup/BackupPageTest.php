<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;

/** Halaman Admin → Backup: hak akses, tampilan, permintaan manual, unduhan, dan banner dashboard. */
class BackupPageTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupSandbox;

    private const NAMA_SAH = 'backup-20261010-233000-auto.zip';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupSandbox();
        config(['backup.disk_warn_mb' => 0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackupSandbox();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ------------------------------------------------------------------
    // Hak akses
    // ------------------------------------------------------------------

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('admin.backup.index'))->assertRedirect(route('login'));
        $this->post(route('admin.backup.store'))->assertRedirect(route('login'));
        $this->get(route('admin.backup.download', self::NAMA_SAH))->assertRedirect(route('login'));
    }

    public function test_kasir_dan_gudang_ditolak_di_semua_route(): void
    {
        $info = $this->backups->create();

        foreach ([User::factory()->kasir()->create(), User::factory()->gudang()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.backup.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.backup.store'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.backup.download', $info['name']))->assertForbidden();
        }

        $this->assertNull($this->backups->pendingRequest(), 'permintaan dari non-admin tidak boleh masuk antrean');
    }

    public function test_menu_backup_hanya_tampil_untuk_admin(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee(route('admin.backup.index'), false);

        $this->actingAs(User::factory()->kasir()->create())->get(route('kasir.pos'))
            ->assertOk()
            ->assertDontSee(route('admin.backup.index'), false);
    }

    // ------------------------------------------------------------------
    // Tampilan
    // ------------------------------------------------------------------

    public function test_halaman_menampilkan_daftar_status_dan_tombol(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 23:30:00', 'Asia/Jakarta'));
        $info = $this->backups->create();
        $this->backups->recordHeartbeat();

        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('Backup Sekarang')
            ->assertSee($info['name'])
            ->assertSee('10 Oct 2026, 23:30 WIB')
            ->assertSee('Tiap hari pukul 23:00 WIB')
            ->assertSee('Aktif')
            ->assertSee(route('admin.backup.download', $info['name']), false)
            ->assertDontSee('Belum ada backup sama sekali');
    }

    public function test_halaman_tanpa_backup_menampilkan_peringatan_dan_tidak_error(): void
    {
        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('Belum ada backup sama sekali')
            ->assertSee('Penjadwal tidak aktif');
    }

    public function test_halaman_tetap_terbuka_walau_folder_backup_tidak_ada(): void
    {
        config(['backup.path' => $this->sandbox.'/tidak-ada']);

        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('Belum ada backup');
    }

    public function test_nama_file_di_halaman_tidak_bisa_menyisipkan_html(): void
    {
        // Nama sah hanya berpola ketat, tetapi pastikan keluaran tetap di-escape.
        $this->touchBackup('20261010-233000');

        $html = $this->actingAs($this->admin())->get(route('admin.backup.index'))->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('backup-20261010-233000-auto.zip', $html);
    }

    // ------------------------------------------------------------------
    // Backup Sekarang
    // ------------------------------------------------------------------

    public function test_backup_sekarang_menaruh_permintaan_bukan_menjalankan_dump(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.backup.store'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($admin->id, $this->backups->pendingRequest()['requested_by']);
        $this->assertSame([], $this->dumper->calls, 'dump TIDAK boleh berjalan di dalam request web');
    }

    public function test_klik_ganda_tidak_membuat_dua_permintaan(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.backup.store'));
        $second = $this->actingAs($admin)->post(route('admin.backup.store'));

        $second->assertSessionHas('success', fn ($m) => str_contains($m, 'sudah dalam antrean'));
        $this->assertCount(1, glob($this->backupDir.'/run-requested*'));
    }

    public function test_saat_backup_berjalan_permintaan_ditolak_dengan_pesan_jelas(): void
    {
        $h = fopen($this->backupDir.'/.lock', 'c');
        flock($h, LOCK_EX);

        $this->actingAs($this->admin())->post(route('admin.backup.store'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sedang berjalan'));
        $this->assertNull($this->backups->pendingRequest());

        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertSee('Backup sedang berjalan...')
            ->assertSee('window.location.reload', false);

        flock($h, LOCK_UN);
        fclose($h);
    }

    public function test_tombol_nonaktif_dan_halaman_memuat_ulang_saat_menunggu_penjadwal(): void
    {
        $this->backups->requestRun(1);

        $this->actingAs($this->admin())->get(route('admin.backup.index'))
            ->assertSee('Menunggu penjadwal...')
            ->assertSee('disabled', false)
            ->assertSee('window.location.reload', false);
    }

    public function test_folder_bermasalah_ditampilkan_sebagai_pesan_bukan_error_500(): void
    {
        config(['backup.path' => '/proc/tidak-bisa-ditulis']);

        $this->actingAs($this->admin())->post(route('admin.backup.store'))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Folder backup'));
    }

    public function test_permintaan_dibatasi_enam_kali_per_menit(): void
    {
        $admin = $this->admin();

        foreach (range(1, 6) as $i) {
            $this->actingAs($admin)->post(route('admin.backup.store'))->assertRedirect();
        }

        $this->actingAs($admin)->post(route('admin.backup.store'))->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // Unduh
    // ------------------------------------------------------------------

    public function test_admin_mengunduh_backup_dengan_header_aman(): void
    {
        $info = $this->backups->create();
        Log::spy();

        $response = $this->actingAs($this->admin())->get(route('admin.backup.download', $info['name']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($info['name'], $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(file_get_contents($info['path']), $response->streamedContent() ?: file_get_contents($response->getFile()->getPathname()));

        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'Backup diunduh' && $ctx['file'] === $info['name']);
    }

    public function test_unduh_file_yang_tidak_ada_404(): void
    {
        $this->actingAs($this->admin())->get(route('admin.backup.download', self::NAMA_SAH))->assertNotFound();
    }

    public function test_unduh_menolak_nama_berbahaya(): void
    {
        file_put_contents($this->sandbox.'/rahasia.txt', 'RAHASIA');
        $this->touchBackup('20261010-233000');
        $admin = $this->admin();

        foreach ([
            '..%2Frahasia.txt',
            '..%2F..%2Fetc%2Fpasswd',
            'backup-20261010-233000-auto.zip%2F..%2F..%2Frahasia.txt',
            'backup-20261010-233000-hack.zip',
            'backup-20261010-233000-auto.zip.php',
            '.lock',
            'status.json',
            'scheduler-heartbeat',
        ] as $name) {
            $response = $this->actingAs($admin)->get('/admin/backup/'.$name.'/unduh');
            $response->assertNotFound();
            $this->assertStringNotContainsString('RAHASIA', (string) $response->getContent());
        }
    }

    public function test_unduh_menolak_symlink_ke_file_lain(): void
    {
        file_put_contents($this->sandbox.'/rahasia.txt', 'RAHASIA');
        symlink($this->sandbox.'/rahasia.txt', $this->backupDir.'/'.self::NAMA_SAH);

        $this->actingAs($this->admin())->get(route('admin.backup.download', self::NAMA_SAH))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Banner dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_admin_menampilkan_banner_bila_ada_masalah(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Perhatian: data belum aman')
            ->assertSee('Belum ada backup sama sekali')
            ->assertSee(route('admin.backup.index'), false);
    }

    public function test_dashboard_admin_tanpa_banner_bila_semua_sehat(): void
    {
        $this->backups->create();
        $this->backups->recordHeartbeat();

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Perhatian: data belum aman')
            ->assertDontSee('Perhatian: backup perlu diperiksa');
    }

    public function test_dashboard_tetap_terbuka_walau_folder_backup_rusak(): void
    {
        config(['backup.path' => '/proc/tidak-bisa-ditulis']);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();
    }
}