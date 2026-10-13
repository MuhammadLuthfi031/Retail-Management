<?php

namespace Tests\Support;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseDumper;

/**
 * Pengganti MysqlDumper untuk test di SQLite. Mencatat urutan panggilan dan bisa
 * disuruh gagal pada panggilan tertentu. Isi dump unik per panggilan (nomor urut)
 * supaya test bisa membedakan "dump asli" dari "dump backup pengaman".
 */
class FakeDatabaseDumper implements DatabaseDumper
{
    /** @var list<string> urutan: 'dump' / 'restore' */
    public array $calls = [];

    /** @var list<string> isi SQL yang diterima restore(), berurutan */
    public array $restoredSql = [];

    public bool $failDump = false;

    public ?string $fixedContent = null;

    /** @var list<int> nomor panggilan restore (1-based) yang harus gagal */
    public array $failRestoreCalls = [];

    /** @var list<callable> dipanggil di awal setiap restore (mis. untuk mengintip kondisi sistem) */
    public array $onRestore = [];

    private int $dumpCount = 0;

    private int $restoreCount = 0;

    public function dump(string $targetPath): void
    {
        $this->calls[] = 'dump';

        if ($this->failDump) {
            throw new BackupException('Dump database gagal: simulasi kegagalan');
        }

        $this->dumpCount++;
        file_put_contents(
            $targetPath,
            $this->fixedContent ?? "-- fake dump #{$this->dumpCount}\nINSERT INTO t VALUES (1);\n-- Dump completed on 2026-10-10\n"
        );
    }

    public function restore(string $sqlPath): void
    {
        $this->calls[] = 'restore';
        $this->restoreCount++;
        $this->restoredSql[] = (string) file_get_contents($sqlPath);

        foreach ($this->onRestore as $callback) {
            $callback();
        }

        if (in_array($this->restoreCount, $this->failRestoreCalls, true)) {
            throw new BackupException('Impor database gagal: simulasi kegagalan');
        }
    }
}