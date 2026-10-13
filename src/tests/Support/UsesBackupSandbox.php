<?php

namespace Tests\Support;

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/**
 * Sandbox untuk test backup: folder backup & folder unggahan sementara (test TIDAK
 * pernah menyentuh storage/app/public asli), dumper palsu, dan helper membaca zip/xlsx.
 */
trait UsesBackupSandbox
{
    protected string $sandbox;

    protected string $backupDir;

    protected string $uploadDir;

    protected FakeDatabaseDumper $dumper;

    protected BackupService $backups;

    protected function setUpBackupSandbox(): void
    {
        $this->sandbox = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(5));
        $this->backupDir = $this->sandbox.'/out';
        $this->uploadDir = $this->sandbox.'/public';
        mkdir($this->backupDir, 0777, true);
        mkdir($this->uploadDir.'/products', 0777, true);
        mkdir($this->uploadDir.'/purchase-payments', 0777, true);

        config([
            'backup.path' => $this->backupDir,
            'backup.storage_source' => $this->uploadDir,
            'backup.min_free_mb' => 1,
            'backup.retention_days' => 14,
            'backup.keep_min' => 3,
            'backup.timezone' => 'Asia/Jakarta',
        ]);

        $this->dumper = new FakeDatabaseDumper();
        $this->app->instance(DatabaseDumper::class, $this->dumper);
        $this->backups = $this->app->make(BackupService::class);
    }

    protected function tearDownBackupSandbox(): void
    {
        \Carbon\Carbon::setTestNow();

        if (isset($this->sandbox) && is_dir($this->sandbox)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                ($item->isDir() && ! $item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->sandbox);
        }
    }

    /** Buat file unggahan palsu di folder sumber. */
    protected function putUpload(string $relative, string $content): void
    {
        $path = $this->uploadDir.'/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** @return list<string> nama entri di dalam zip */
    protected function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, 'zip tidak bisa dibuka');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    protected function zipRead(string $zipPath, string $entry): string
    {
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $content = $zip->getFromName($entry);
        $zip->close();
        $this->assertNotFalse($content, "entri {$entry} tidak ada");

        return $content;
    }

    /** @return array<string, mixed> */
    protected function manifestOf(string $zipPath): array
    {
        return json_decode($this->zipRead($zipPath, 'manifest.json'), true);
    }

    /** Daftar nama file nyata di folder backup (tanpa . dan ..). @return list<string> */
    protected function backupDirFiles(): array
    {
        return array_values(array_diff(scandir($this->backupDir), ['.', '..']));
    }

    /**
     * Baca semua lembar xlsx: [nama lembar => [baris => [nilai...]]].
     *
     * @return array<string, list<list<mixed>>>
     */
    protected function readXlsx(string $xlsxPath): array
    {
        $reader = new Reader();
        $reader->open($xlsxPath);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    /** Ekstrak arsip-data.xlsx dari paket backup ke file sementara dan baca. */
    protected function readXlsxFromBackup(string $zipPath): array
    {
        $tmp = $this->sandbox.'/extracted-'.bin2hex(random_bytes(3)).'.xlsx';
        file_put_contents($tmp, $this->zipRead($zipPath, 'arsip-data.xlsx'));

        return $this->readXlsx($tmp);
    }

    /** Buat file backup kosong bernama pola sah pada waktu tertentu (untuk test daftar/retensi). */
    protected function touchBackup(string $ymdHis, string $label = 'auto'): string
    {
        $name = "backup-{$ymdHis}-{$label}.zip";
        file_put_contents($this->backupDir.'/'.$name, 'x');

        return $name;
    }
}