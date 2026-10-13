<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupList extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'Tampilkan daftar backup yang tersedia dan status backup terakhir';

    public function handle(BackupService $backups): int
    {
        $items = $backups->list();
        $status = $backups->status();

        if ($items === []) {
            $this->warn('Belum ada backup. Jalankan: php artisan backup:run');
        } else {
            $this->table(
                ['Nama file', 'Jenis', 'Dibuat (WIB)', 'Ukuran'],
                array_map(fn ($i) => [
                    $i['name'],
                    $i['label'],
                    $i['created_at']->format('d-m-Y H:i'),
                    Number::fileSize($i['size'], 1),
                ], $items)
            );
        }

        $last = $status['last_success_at'];
        $this->line('Backup sukses terakhir : '.($last ? $last->format('d-m-Y H:i') : 'belum ada'));

        if ($status['stale']) {
            $this->warn('PERINGATAN: backup terakhir sudah terlalu lama atau belum ada.');
        }
        if ($status['last_error']) {
            $this->warn('Kegagalan terakhir      : '.$status['last_error']);
        }
        if ($status['free_bytes'] !== null) {
            $this->line('Ruang disk tersisa     : '.Number::fileSize($status['free_bytes'], 1));
        }

        return self::SUCCESS;
    }
}