<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupRun extends Command
{
    protected $signature = 'backup:run {--manual : Tandai sebagai backup manual (default: otomatis)}';

    protected $description = 'Buat backup lengkap: database + file unggahan + arsip Excel';

    public function handle(BackupService $backups): int
    {
        try {
            $info = $backups->create($this->option('manual') ? 'manual' : 'auto');
        } catch (BackupException $e) {
            $this->error('Backup GAGAL: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup berhasil: {$info['name']}");
        $this->line('  Ukuran        : '.Number::fileSize($info['size'], 1));
        $this->line('  Tabel         : '.($info['tables'] ?? '-'));
        $this->line('  File unggahan : '.$info['storage_files']);

        foreach ($info['warnings'] as $warning) {
            $this->warn("  Peringatan: {$warning}");
        }

        if ($info['pruned'] !== []) {
            $this->line('  Backup lama dihapus (melewati masa retensi): '.implode(', ', $info['pruned']));
        }

        return self::SUCCESS;
    }
}