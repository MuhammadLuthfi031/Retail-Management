<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\Backup\ExcelArchive;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRetailData;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;
use ZipArchive;

/**
 * QA-012 — paket backup: pembuatan, arsip Excel, daftar/retensi/status, verifikasi, restore.
 * Memakai FakeDatabaseDumper (SQLite); dump/restore MySQL nyata diuji di MysqlRoundTripTest.
 */
class BackupServiceTest extends TestCase
{
    use BuildsRetailData;
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

    /**
     * Carbon::setTestNow() mengabaikan argumen zona waktu kedua, jadi waktu WIB harus
     * di-parse eksplisit. (Tanpa ini "12:00" terbaca UTC dan batas retensi bergeser 7 jam.)
     */
    private function nowIsWib(string $wallClock): void
    {
        Carbon::setTestNow(Carbon::parse($wallClock, 'Asia/Jakarta'));
    }

    // ------------------------------------------------------------------
    // Pembuatan paket
    // ------------------------------------------------------------------

    public function test_membuat_paket_zip_lengkap_dengan_nama_berzona_waktu_wib(): void
    {
        // 16:30 UTC = 23:30 WIB. Zona waktu aplikasi (UTC, QA-008) tidak boleh memengaruhi nama file.
        Carbon::setTestNow('2026-10-10 16:30:00 UTC');
        $this->putUpload('products/a.jpg', 'JPG');
        $this->putUpload('purchase-payments/p.png', 'PNG');
        $this->putUpload('.gitignore', '*');

        $info = $this->backups->create();

        $this->assertSame('backup-20261010-233000-auto.zip', $info['name']);
        $this->assertSame([
            'arsip-data.xlsx',
            'database.sql',
            'manifest.json',
            'storage-public/products/a.jpg',
            'storage-public/purchase-payments/p.png',
        ], $this->zipEntries($info['path']));
        $this->assertSame(2, $info['storage_files']);
    }

    public function test_dua_backup_berlabel_sama_dalam_detik_yang_sama_tidak_bentrok(): void
    {
        Carbon::setTestNow('2026-10-10 16:30:00 UTC');   // waktu dibekukan: detik identik

        $a = $this->backups->create('manual');
        $b = $this->backups->create('manual');
        $c = $this->backups->create('manual');

        $this->assertSame('backup-20261010-233000-manual.zip', $a['name']);
        $this->assertSame('backup-20261010-233001-manual.zip', $b['name']);
        $this->assertSame('backup-20261010-233002-manual.zip', $c['name']);
        $this->assertCount(3, $this->backups->list());
        $this->assertNull($this->backups->status()['last_error'], 'bukan kegagalan');
        $this->assertSame('2026-10-10T23:30:00+07:00', $this->manifestOf($b['path'])['created_at'], 'manifest mencatat waktu sebenarnya');
    }

    public function test_manifest_cocok_dengan_isi_paket(): void
    {
        Carbon::setTestNow('2026-10-10 16:30:00 UTC');
        $this->putUpload('products/a.jpg', 'JPG');

        $info = $this->backups->create('manual');
        $manifest = $this->manifestOf($info['path']);

        $this->assertSame('manual', $manifest['label']);
        $this->assertSame('2026-10-10T23:30:00+07:00', $manifest['created_at']);
        $this->assertSame(hash('sha256', $this->zipRead($info['path'], 'database.sql')), $manifest['files']['database.sql']['sha256']);
        $this->assertSame(1, $manifest['storage']['files']);
        $this->assertSame(3, $manifest['storage']['bytes']);
        $this->assertGreaterThan(0, $manifest['database']['tables']);
        $this->assertSame([], $manifest['warnings']);
    }

    public function test_tidak_menyisakan_folder_kerja_dan_status_sukses_tercatat(): void
    {
        $info = $this->backups->create();

        $files = $this->backupDirFiles();
        $this->assertContains($info['name'], $files);
        $this->assertSame([], array_values(array_filter($files, fn ($f) => str_starts_with($f, '.tmp-'))));

        $status = $this->backups->status();
        $this->assertNotNull($status['last_success_at']);
        $this->assertNull($status['last_error']);
        $this->assertFalse($status['stale']);
    }

    public function test_label_tidak_dikenal_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->backups->create('../../x');
    }

    public function test_dump_gagal_tidak_meninggalkan_apa_pun_dan_backup_lama_aman(): void
    {
        $good = $this->backups->create();
        $this->dumper->failDump = true;

        try {
            Carbon::setTestNow(now()->addMinute());
            $this->backups->create();
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Dump database gagal', $e->getMessage());
        }

        $this->assertCount(1, $this->backups->list());
        $this->assertSame($good['name'], $this->backups->list()[0]['name']);
        $this->assertSame([], array_values(array_filter($this->backupDirFiles(), fn ($f) => str_starts_with($f, '.tmp-'))));

        $status = $this->backups->status();
        $this->assertStringContainsString('Dump database gagal', $status['last_error']);
        $this->assertNotNull($status['last_failure_at']);
        $this->assertNotNull($status['last_success_at'], 'riwayat sukses terakhir tidak boleh hilang');
    }

    public function test_dump_kosong_ditolak(): void
    {
        $this->dumper->fixedContent = '';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Dump database kosong');

        $this->backups->create();
    }

    public function test_kegagalan_excel_tidak_menggagalkan_backup_tetapi_diberi_peringatan(): void
    {
        $this->app->instance(ExcelArchive::class, new class extends ExcelArchive {
            public function write(string $path, \DateTimeInterface $createdAt): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $info = $this->app->make(BackupService::class)->create();

        $this->assertNotContains('arsip-data.xlsx', $this->zipEntries($info['path']));
        $this->assertStringContainsString('Excel', $info['warnings'][0]);
        $manifest = $this->manifestOf($info['path']);
        $this->assertNull($manifest['files']['arsip-data.xlsx']);
        $this->assertNotEmpty($manifest['warnings']);
    }

    public function test_ruang_disk_kurang_menolak_sebelum_dump_dimulai(): void
    {
        config(['backup.min_free_mb' => 999_999_999_999]);

        try {
            $this->backups->create();
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Ruang disk tidak cukup', $e->getMessage());
        }

        $this->assertSame([], $this->dumper->calls, 'dump tidak boleh dimulai bila disk tidak cukup');
    }

    public function test_backup_bersamaan_ditolak_oleh_kunci(): void
    {
        $holder = fopen($this->backupDir.'/.lock', 'c');
        flock($holder, LOCK_EX);

        try {
            $this->backups->create();
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('sedang berjalan', $e->getMessage());
        }

        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertNotEmpty($this->backups->create()['name'], 'setelah kunci dilepas backup harus bisa jalan');
    }

    // ------------------------------------------------------------------
    // Arsip Excel
    // ------------------------------------------------------------------

    public function test_arsip_excel_memuat_data_dan_tanpa_kolom_sensitif(): void
    {
        $user = User::forceCreate([
            'name' => 'Budi Admin', 'email' => 'budi@toko.test', 'role' => 'admin',
            'password' => bcrypt('rahasia-banget'), 'is_active' => true, 'remember_token' => 'TOKEN-RAHASIA-123',
        ]);
        $product = $this->makeProduct(['name' => 'Kopi Sachet']);

        $info = $this->backups->create();
        $sheets = $this->readXlsxFromBackup($info['path']);

        $this->assertSame(['id', 'name', 'email', 'role', 'is_active', 'created_at'], $sheets['Pengguna'][0]);
        $names = array_column($sheets['Pengguna'], 1);
        $this->assertContains('Budi Admin', $names);

        $all = json_encode($sheets);
        $this->assertStringNotContainsString($user->password, $all, 'hash password tidak boleh ada di Excel');
        $this->assertStringNotContainsString('TOKEN-RAHASIA-123', $all, 'remember_token tidak boleh ada di Excel');

        $this->assertContains('Kopi Sachet', array_column($sheets['Produk'], array_search('name', $sheets['Produk'][0])));
        $this->assertArrayHasKey('Info', $sheets);
        $this->assertArrayNotHasKey('Sessions', $sheets);
    }

    public function test_angka_ditulis_sebagai_angka_bukan_teks(): void
    {
        $product = $this->makeProduct(['name' => 'Beras']);
        DB::table('products')->where('id', $product->id)->update(['average_cost' => 1234.5, 'stock' => 12.5]);

        $info = $this->backups->create();
        $sheets = $this->readXlsxFromBackup($info['path']);

        $header = $sheets['Produk'][0];
        $row = $sheets['Produk'][1];
        $this->assertSame(1234.5, $row[array_search('average_cost', $header)], 'harus angka, bukan teks "1234.500"');
        $this->assertSame(12.5, $row[array_search('stock', $header)]);
    }

    public function test_teks_berawalan_rumus_tidak_menjadi_rumus_excel(): void
    {
        $evil = '=HYPERLINK("http://jahat.example","klik")';
        $this->makeProduct(['name' => $evil]);

        $info = $this->backups->create();

        // Periksa XML mentah: tidak boleh ada elemen rumus <f>, dan teksnya tetap utuh sebagai teks.
        $tmp = $this->sandbox.'/inj.xlsx';
        file_put_contents($tmp, $this->zipRead($info['path'], 'arsip-data.xlsx'));
        $xlsx = new ZipArchive();
        $xlsx->open($tmp);
        $sheetXml = '';
        for ($i = 0; $i < $xlsx->numFiles; $i++) {
            $n = $xlsx->getNameIndex($i);
            if (str_starts_with($n, 'xl/worksheets/') || $n === 'xl/sharedStrings.xml') {
                $sheetXml .= $xlsx->getFromIndex($i);
            }
        }
        $xlsx->close();

        $this->assertDoesNotMatchRegularExpression('/<f[ >\/]/', $sheetXml, 'sel berawalan "=" tidak boleh menjadi rumus');
        $this->assertStringContainsString('HYPERLINK', $sheetXml, 'teksnya tetap tersimpan sebagai teks');
    }

    public function test_lembar_dipecah_bila_melebihi_batas_baris(): void
    {
        foreach (range(1, 5) as $n) {
            $this->makeCategory("Kat {$n}");
        }
        config(['backup.excel_tables' => ['categories' => ['label' => 'Kategori', 'columns' => ['id', 'name']]]]);

        $path = $this->sandbox.'/split.xlsx';
        (new ExcelArchive(3))->write($path, now());   // 3 baris/lembar = 1 header + 2 data
        $sheets = $this->readXlsx($path);

        $this->assertSame(['Info', 'Kategori', 'Kategori (2)', 'Kategori (3)'], array_keys($sheets));
        foreach (['Kategori', 'Kategori (2)', 'Kategori (3)'] as $s) {
            $this->assertSame(['id', 'name'], $sheets[$s][0], "header harus diulang di {$s}");
        }
        $dataRows = array_sum(array_map(fn ($s) => count($sheets[$s]) - 1, ['Kategori', 'Kategori (2)', 'Kategori (3)']));
        $this->assertSame(5, $dataRows, 'tidak ada baris yang hilang atau ganda');
    }

    public function test_tabel_yang_tidak_ada_dilewati_dan_nama_lembar_dibersihkan(): void
    {
        $this->makeCategory('Satu');
        config(['backup.excel_tables' => [
            'tabel_hantu' => ['label' => 'Hantu', 'columns' => null],
            'categories' => ['label' => 'Ka[te]:go*ri?/\\ dengan nama yang sangat sangat panjang sekali', 'columns' => null],
        ]]);

        $path = $this->sandbox.'/skip.xlsx';
        $warnings = (new ExcelArchive())->write($path, now());
        $sheets = $this->readXlsx($path);

        $this->assertStringContainsString('tabel_hantu', $warnings[0]);
        $sheetNames = array_keys($sheets);
        $this->assertCount(2, $sheetNames); // Info + Kategori; "Hantu" tidak membuat lembar kosong
        $this->assertLessThanOrEqual(31, mb_strlen($sheetNames[1]));
        $this->assertDoesNotMatchRegularExpression('/[\[\]:*?\/\\\\]/', $sheetNames[1]);
    }

    // ------------------------------------------------------------------
    // Daftar, pencarian, retensi, status
    // ------------------------------------------------------------------

    public function test_daftar_terbaru_dulu_dan_mengabaikan_file_lain(): void
    {
        $this->touchBackup('20261001-230000');
        $this->touchBackup('20261003-230000', 'manual');
        $this->touchBackup('20261002-230000');
        file_put_contents($this->backupDir.'/backup-bad.zip', 'x');
        file_put_contents($this->backupDir.'/catatan.txt', 'x');

        $list = $this->backups->list();

        $this->assertSame([
            'backup-20261003-230000-manual.zip',
            'backup-20261002-230000-auto.zip',
            'backup-20261001-230000-auto.zip',
        ], array_column($list, 'name'));
        $this->assertSame('manual', $list[0]['label']);
        $this->assertSame('Asia/Jakarta', $list[0]['created_at']->timezoneName);
        $this->assertSame('23:00', $list[0]['created_at']->format('H:i'));
    }

    #[DataProvider('namaBerbahaya')]
    public function test_find_menolak_nama_berbahaya(string $name): void
    {
        file_put_contents($this->sandbox.'/rahasia.txt', 'x');

        $this->assertNull($this->backups->find($name));
    }

    public static function namaBerbahaya(): array
    {
        return [
            'traversal' => ['../rahasia.txt'],
            'traversal di dalam nama sah' => ['backup-20261010-233000-auto.zip/../../rahasia.txt'],
            'path mutlak' => ['/etc/passwd'],
            'ekstensi ganda' => ['backup-20261010-233000-auto.zip.php'],
            'label tidak sah' => ['backup-20261010-233000-hack.zip'],
            'kosong' => [''],
            'subfolder' => ['sub/backup-20261010-233000-auto.zip'],
            'newline' => ["backup-20261010-233000-auto.zip\n"],
        ];
    }

    public function test_find_menolak_symlink_dan_menemukan_file_sah(): void
    {
        $real = $this->touchBackup('20261010-233000');
        $this->assertSame($this->backupDir.'/'.$real, $this->backups->find($real));

        file_put_contents($this->sandbox.'/rahasia.txt', 'x');
        symlink($this->sandbox.'/rahasia.txt', $this->backupDir.'/backup-20261011-233000-auto.zip');
        $this->assertNull($this->backups->find('backup-20261011-233000-auto.zip'));
    }

    public function test_prune_menghapus_yang_melewati_retensi_dan_menghormati_batas_tepat(): void
    {
        $this->nowIsWib('2026-10-30 12:00:00');   // cutoff = 16-10 12:00:00
        foreach (['20261029-230000', '20261020-230000', '20261017-230000'] as $recent) {
            $this->touchBackup($recent);
        }
        $this->touchBackup('20261016-120000');   // TEPAT di batas -> dipertahankan
        $this->touchBackup('20261016-115959');   // 1 detik lewat -> dihapus
        $this->touchBackup('20261001-230000');   // jelas lewat -> dihapus

        $deleted = $this->backups->prune();

        sort($deleted);
        $this->assertSame([
            'backup-20261001-230000-auto.zip',
            'backup-20261016-115959-auto.zip',
        ], $deleted);
        $this->assertCount(4, $this->backups->list());
    }

    public function test_prune_selalu_menyisakan_keep_min_walau_semuanya_kedaluwarsa(): void
    {
        $this->nowIsWib('2026-10-30 12:00:00');
        foreach (['20260901', '20260902', '20260903', '20260904'] as $d) {
            $this->touchBackup("{$d}-230000");
        }

        $deleted = $this->backups->prune();

        $this->assertSame(['backup-20260901-230000-auto.zip'], $deleted);
        $this->assertCount(3, $this->backups->list());
    }

    public function test_prune_tidak_dijalankan_bila_backup_baru_gagal(): void
    {
        $this->nowIsWib('2026-10-30 12:00:00');
        foreach (['20260901', '20260902', '20260903', '20260904', '20260905'] as $d) {
            $this->touchBackup("{$d}-230000");
        }
        $this->dumper->failDump = true;

        try {
            $this->backups->create();
        } catch (BackupException) {
        }

        $this->assertCount(5, $this->backups->list(), 'backup lama tidak boleh dihapus ketika backup baru gagal');
    }

    public function test_create_menjalankan_prune_dan_melaporkannya(): void
    {
        $this->nowIsWib('2026-10-30 12:00:00');
        foreach (['20260901', '20260902', '20260903'] as $d) {
            $this->touchBackup("{$d}-230000");
        }

        $info = $this->backups->create();

        $this->assertSame(['backup-20260901-230000-auto.zip'], $info['pruned']);
    }

    public function test_status_menandai_terlambat_sesuai_ambang_26_jam(): void
    {
        $this->nowIsWib('2026-10-10 12:00:00');
        $this->assertTrue($this->backups->status()['stale'], 'tanpa backup = terlambat');
        $this->assertNull($this->backups->status()['last_success_at']);

        $this->backups->create();
        $this->assertFalse($this->backups->status()['stale']);

        $this->nowIsWib('2026-10-11 13:00:00'); // +25 jam
        $this->assertFalse($this->backups->status()['stale']);

        $this->nowIsWib('2026-10-11 14:30:00'); // +26,5 jam
        $this->assertTrue($this->backups->status()['stale']);
    }

    public function test_status_memakai_backup_yang_disalin_manual_bila_status_json_tidak_ada(): void
    {
        $this->nowIsWib('2026-10-10 12:00:00');
        $this->touchBackup('20261010-100000');

        $status = $this->backups->status();

        $this->assertSame('10:00', $status['last_success_at']->format('H:i'));
        $this->assertFalse($status['stale']);
    }

    // ------------------------------------------------------------------
    // Verifikasi
    // ------------------------------------------------------------------

    public function test_verify_menolak_file_rusak(): void
    {
        file_put_contents($this->sandbox.'/rusak.zip', 'ini bukan zip');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('rusak atau bukan arsip zip');

        $this->backups->verifyArchive($this->sandbox.'/rusak.zip');
    }

    public function test_verify_menolak_checksum_yang_tidak_cocok(): void
    {
        $info = $this->backups->create();
        $zip = new ZipArchive();
        $zip->open($info['path']);
        $zip->addFromString('database.sql', "-- sudah diubah orang\nDROP TABLE users;\n-- Dump completed\n");
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Checksum');

        $this->backups->verifyArchive($info['path']);
    }

    public function test_verify_menolak_paket_tanpa_manifest(): void
    {
        $path = $this->sandbox.'/tanpa-manifest.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'x');
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('manifest.json');

        $this->backups->verifyArchive($path);
    }

    public function test_verify_menolak_versi_format_dari_masa_depan(): void
    {
        $info = $this->backups->create();
        $manifest = $this->manifestOf($info['path']);
        $manifest['format_version'] = 99;
        $zip = new ZipArchive();
        $zip->open($info['path']);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('versi aplikasi yang lebih baru');

        $this->backups->verifyArchive($info['path']);
    }

    #[DataProvider('entriBerbahaya')]
    public function test_verify_menolak_nama_entri_berbahaya(string $entry): void
    {
        $info = $this->backups->create();
        $zip = new ZipArchive();
        $zip->open($info['path']);
        $zip->addFromString($entry, 'JAHAT');
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('tidak aman');

        $this->backups->verifyArchive($info['path']);
    }

    public static function entriBerbahaya(): array
    {
        return [
            'keluar folder' => ['../evil.txt'],
            'traversal di storage' => ['storage-public/../../evil.txt'],
            'path mutlak' => ['/etc/evil.txt'],
            'segmen kosong' => ['storage-public//evil.txt'],
            'backslash' => ['storage-public\\..\\evil.txt'],
            'drive letter' => ['storage-public/C:/evil.txt'],
            'entri tak dikenal' => ['evil.php'],
        ];
    }

    // ------------------------------------------------------------------
    // Restore
    // ------------------------------------------------------------------

    public function test_restore_membuat_backup_pengaman_sebelum_menyentuh_database(): void
    {
        $original = $this->backups->create();
        $safetyExisted = null;
        $this->dumper->onRestore[] = function () use (&$safetyExisted) {
            $safetyExisted = glob($this->backupDir.'/backup-*-pra-restore.zip');
        };

        $result = $this->backups->restore($original['path']);

        $this->assertSame(['dump', 'dump', 'restore'], $this->dumper->calls, 'dump asli, dump pengaman, baru restore');
        $this->assertCount(1, $safetyExisted, 'backup pengaman HARUS sudah ada saat database ditimpa');
        $this->assertStringEndsWith('-pra-restore.zip', $result['safety_backup']);
        $this->assertStringContainsString('fake dump #1', $this->dumper->restoredSql[0], 'yang diimpor adalah isi backup asli');
    }

    public function test_restore_memulihkan_file_unggahan_dan_tidak_menghapus_file_lain(): void
    {
        $this->putUpload('products/a.jpg', 'ASLI');
        $this->putUpload('purchase-payments/p.png', 'BUKTI');
        $original = $this->backups->create();

        $this->putUpload('products/a.jpg', 'SUDAH-BERUBAH');
        $this->putUpload('products/baru.jpg', 'BARU');
        unlink($this->uploadDir.'/purchase-payments/p.png');

        $result = $this->backups->restore($original['path']);

        $this->assertSame('ASLI', file_get_contents($this->uploadDir.'/products/a.jpg'));
        $this->assertSame('BUKTI', file_get_contents($this->uploadDir.'/purchase-payments/p.png'));
        $this->assertSame('BARU', file_get_contents($this->uploadDir.'/products/baru.jpg'), 'file yang tidak ada di backup dibiarkan');
        $this->assertSame(2, $result['files_restored']);
        $this->assertSame([], glob($this->uploadDir.'/*/*.restoring'), 'tidak ada file sementara tersisa');
    }

    public function test_restore_gagal_mengembalikan_dari_backup_pengaman(): void
    {
        $original = $this->backups->create();
        $this->dumper->failRestoreCalls = [1];

        try {
            $this->backups->restore($original['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('sudah dikembalikan ke kondisi sebelum restore', $e->getMessage());
        }

        $this->assertCount(2, $this->dumper->restoredSql);
        $this->assertStringContainsString('fake dump #1', $this->dumper->restoredSql[0], 'percobaan 1: backup yang diminta');
        $this->assertStringContainsString('fake dump #2', $this->dumper->restoredSql[1], 'percobaan 2: backup pengaman');
    }

    public function test_restore_gagal_dan_pengembalian_gagal_menyebut_backup_pengaman(): void
    {
        $original = $this->backups->create();
        $this->dumper->failRestoreCalls = [1, 2];

        try {
            $this->backups->restore($original['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('pengembalian otomatis juga gagal', $e->getMessage());
            $this->assertMatchesRegularExpression('/backup-\d{8}-\d{6}-pra-restore\.zip/', $e->getMessage());
        }
    }

    public function test_restore_dibatalkan_total_bila_backup_pengaman_gagal(): void
    {
        $original = $this->backups->create();
        $this->dumper->failDump = true;

        try {
            $this->backups->restore($original['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException) {
        }

        $this->assertNotContains('restore', $this->dumper->calls, 'database tidak boleh disentuh tanpa backup pengaman');
    }

    public function test_restore_menolak_paket_berbahaya_sebelum_menyentuh_apa_pun(): void
    {
        $info = $this->backups->create();
        $zip = new ZipArchive();
        $zip->open($info['path']);
        $zip->addFromString('storage-public/../../evil.txt', 'JAHAT');
        $zip->close();
        $callsBefore = $this->dumper->calls;

        try {
            $this->backups->restore($info['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('tidak aman', $e->getMessage());
        }

        $this->assertSame($callsBefore, $this->dumper->calls, 'tidak ada dump/restore baru');
        $this->assertFileDoesNotExist($this->sandbox.'/evil.txt');
        $this->assertFileDoesNotExist($this->uploadDir.'/../evil.txt');
    }

    public function test_restore_tidak_menulis_keluar_folder_lewat_symlink(): void
    {
        $this->putUpload('products/a.jpg', 'ASLI');
        $original = $this->backups->create();

        // Setelah backup, "products" diganti symlink ke folder di luar area unggahan.
        $outside = $this->sandbox.'/di-luar';
        mkdir($outside);
        unlink($this->uploadDir.'/products/a.jpg');
        rmdir($this->uploadDir.'/products');
        symlink($outside, $this->uploadDir.'/products');

        try {
            $this->backups->restore($original['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('di luar folder unggahan', $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])), 'tidak ada yang boleh tertulis di luar');
    }

    public function test_restore_bersamaan_dengan_backup_lain_ditolak(): void
    {
        $original = $this->backups->create();
        $holder = fopen($this->backupDir.'/.lock', 'c');
        flock($holder, LOCK_EX);

        try {
            $this->backups->restore($original['path']);
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('sedang berjalan', $e->getMessage());
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        $this->assertNotContains('restore', $this->dumper->calls);
    }
}