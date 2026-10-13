<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Penjadwalan (QA-012) — dijalankan container `scheduler` (php artisan schedule:work)
|--------------------------------------------------------------------------
| Zona waktu eksplisit dari config('backup.timezone') supaya "23:00" selalu WIB,
| tidak bergantung zona waktu aplikasi/server (QA-008).
*/

// Backup otomatis harian. Kegagalan tercatat di status backup (BackupService) & log.
Schedule::command('backup:run')
    ->dailyAt((string) config('backup.schedule_at'))
    ->timezone((string) config('backup.timezone'))
    ->withoutOverlapping(60);

// Tiap menit: detak penjadwal + proses permintaan "Backup Sekarang" dari halaman Admin.
Schedule::command('backup:tick')
    ->everyMinute()
    ->withoutOverlapping(30);