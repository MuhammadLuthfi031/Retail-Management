<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {file : Nama file backup (mis. backup-20261010-230000-auto.zip) atau path lengkap ke file .zip}
        {--force : Lewati konfirmasi (berbahaya; untuk skrip)}';

    protected $description = 'PULIHKAN database & file dari backup. DESTRUKTIF: seluruh data sekarang diganti.';

    public function handle(BackupService $backups): int
    {
        $path = $this->resolvePath($backups, (string) $this->argument('file'));
        if ($path === null) {
            $this->error('File backup tidak ditemukan. Lihat daftar dengan: php artisan backup:list');

            return self::FAILURE;
        }

        try {
            $manifest = $backups->verifyArchive($path);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $createdAt = Carbon::parse($manifest['created_at'])->setTimezone(config('backup.timezone'));
        $this->newLine();
        $this->line('Backup yang akan dipulihkan:');
        $this->line('  Dibuat        : '.$createdAt->format('d-m-Y H:i'));
        $this->line('  Jenis         : '.($manifest['label'] ?? '-'));
        $this->line('  Database      : '.($manifest['database']['name'] ?? '-'));
        $this->line('  File unggahan : '.($manifest['storage']['files'] ?? 0));
        $this->newLine();
        $this->warn('SEMUA data di database SEKARANG akan DIGANTI dengan isi backup ini.');
        $this->warn('Transaksi yang terjadi SETELAH '.$createdAt->format('d-m-Y H:i').' akan HILANG dari sistem.');
        $this->line('Sebelum menimpa, sistem otomatis membuat backup pengaman dari kondisi sekarang.');
        $this->newLine();

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Mode non-interaktif membutuhkan opsi --force.');

                return self::FAILURE;
            }

            if ($this->ask('Ketik PULIHKAN (huruf besar) untuk melanjutkan') !== 'PULIHKAN') {
                $this->info('Dibatalkan. Tidak ada yang diubah.');

                return self::FAILURE;
            }
        }

        // Selama database diganti, web tidak boleh melayani transaksi. Hanya dilepas bila kita yang menyalakannya.
        $weTookItDown = ! $this->laravel->isDownForMaintenance();
        if ($weTookItDown) {
            $this->callSilent('down');
        }

        try {
            $result = $backups->restore($path);
        } catch (BackupException $e) {
            $this->error('Restore GAGAL: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($weTookItDown) {
                $this->callSilent('up');
            }
        }

        $this->info('Restore berhasil.');
        $this->line("  File unggahan dipulihkan : {$result['files_restored']}");
        $this->line("  Backup pengaman          : {$result['safety_backup']}");
        $this->line('Pengguna perlu login ulang bila sesi mereka tidak ada di backup.');

        return self::SUCCESS;
    }

    private function resolvePath(BackupService $backups, string $file): ?string
    {
        if ($byName = $backups->find($file)) {
            return $byName;
        }

        if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
            return (string) realpath($file);
        }

        return null;
    }
}