<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * Dijalankan penjadwal TIAP MENIT. Dua tugas:
 *  1. Mencatat "detak" — bukti penjadwal hidup (UI memperingatkan Admin bila detak berhenti).
 *  2. Memproses permintaan "Backup Sekarang" dari halaman Admin. Dump tidak dijalankan di
 *     request web (rawan kena batas waktu Nginx/PHP), jadi UI hanya menaruh penanda.
 */
class BackupTick extends Command
{
    protected $signature = 'backup:tick';

    protected $description = 'Catat detak penjadwal & proses permintaan backup manual dari UI (dipanggil tiap menit)';

    public function handle(BackupService $backups): int
    {
        try {
            $backups->recordHeartbeat();
        } catch (BackupException $e) {
            $this->error('Folder backup bermasalah: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($backups->consumeRequest() === null) {
            return self::SUCCESS;
        }

        $this->info('Memproses permintaan backup manual...');

        try {
            $info = $backups->create('manual');
        } catch (BackupException $e) {
            // Permintaan sudah dihapus: tidak diulang tiap menit. Kegagalan tercatat di status & log.
            $this->error('Backup manual GAGAL: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup manual selesai: {$info['name']}");

        return self::SUCCESS;
    }
}