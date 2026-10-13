<?php

namespace App\Services\Backup;

use Illuminate\Container\Attributes\Bind;

/**
 * Pemisah antara logika paket backup (BackupService) dengan mesin database.
 * Implementasi nyata: MysqlDumper. Test memakai pengganti palsu supaya logika
 * paket/retensi/restore bisa diuji di SQLite.
 */
#[Bind(MysqlDumper::class)]
interface DatabaseDumper
{
    /** Tulis dump SQL lengkap database aktif ke $targetPath. Lempar BackupException bila gagal. */
    public function dump(string $targetPath): void;

    /**
     * KOSONGKAN database aktif lalu impor $sqlPath. Hanya dipanggil dari alur restore
     * setelah backup pengaman dibuat. Lempar BackupException bila gagal.
     */
    public function restore(string $sqlPath): void;
}