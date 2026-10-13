<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Dump & restore MySQL lewat klien baris-perintah (mysqldump / mysql).
 *
 * Keamanan:
 *  - Password TIDAK pernah dikirim sebagai argumen (terlihat di `ps`). Kredensial
 *    ditulis ke file opsi sementara berizin 0600 yang SELALU dihapus (finally).
 *  - Perintah dijalankan sebagai array argumen, tanpa shell — tidak ada injeksi.
 *  - Pesan error tidak memuat password; hanya baris terakhir stderr klien.
 *
 * Catatan klien: container `app` (Debian) memasang klien MariaDB. Sudah diuji ke
 * MySQL 8 (akun caching_sha2_password). `--no-tablespaces` WAJIB karena akun aplikasi
 * tidak punya hak PROCESS; tanpanya dump gagal.
 */
class MysqlDumper implements DatabaseDumper
{
    public function dump(string $targetPath): void
    {
        $cfg = $this->connectionConfig();
        $optionsFile = $this->writeOptionsFile($cfg);

        try {
            $process = new Process([
                (string) config('backup.dump_binary'),
                '--defaults-extra-file='.$optionsFile, // HARUS argumen pertama
                '--single-transaction',                // snapshot konsisten tanpa mengunci tabel (kasir tetap jalan)
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$targetPath,
                $cfg['database'],
            ], null, null, null, (float) config('backup.timeout'));

            $process->run();

            if (! $process->isSuccessful()) {
                throw new BackupException('Dump database gagal: '.$this->lastError($process));
            }
        } finally {
            @unlink($optionsFile);
        }

        $this->assertDumpComplete($targetPath);
    }

    public function restore(string $sqlPath): void
    {
        $cfg = $this->connectionConfig();
        $optionsFile = $this->writeOptionsFile($cfg);

        try {
            // Buang SEMUA tabel dulu: tabel yang ada sekarang tetapi tidak ada di backup
            // (mis. dari migrasi yang lebih baru) tidak boleh tersisa dan membuat `migrate` bentrok.
            Schema::dropAllTables();

            $input = fopen($sqlPath, 'rb');
            if ($input === false) {
                throw new BackupException('File SQL backup tidak bisa dibaca.');
            }

            try {
                $process = new Process([
                    (string) config('backup.mysql_binary'),
                    '--defaults-extra-file='.$optionsFile,
                    '--default-character-set=utf8mb4',
                    $cfg['database'],
                ], null, null, $input, (float) config('backup.timeout'));

                $process->run();
            } finally {
                fclose($input);
            }

            if (! $process->isSuccessful()) {
                throw new BackupException('Impor database gagal: '.$this->lastError($process));
            }
        } finally {
            @unlink($optionsFile);
        }
    }

    /**
     * Isi file opsi klien. Nilai dikutip & di-escape (backslash dan tanda kutip ganda)
     * supaya password berkarakter khusus (#, ", \) terbaca utuh. Newline ditolak
     * karena akan memecah baris opsi.
     *
     * TLS & verifikasi sertifikat dimatikan kecuali $cfg['ssl'] true. Opsi ditulis dengan awalan
     * `loose-`: klien MariaDB menerapkannya, sedangkan klien MySQL (Oracle) yang tidak mengenalnya
     * hanya memberi peringatan, bukan error.
     *
     * @param  array{host?: ?string, port?: mixed, socket?: ?string, username: string, password: string, ssl?: bool}  $cfg
     */
    public static function optionsFileContents(array $cfg): string
    {
        $lines = ['[client]'];

        if (! empty($cfg['socket'])) {
            $lines[] = 'socket='.self::quote((string) $cfg['socket']);
        } else {
            $lines[] = 'host='.self::quote((string) ($cfg['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($cfg['port'] ?? 3306);
            $lines[] = 'protocol=TCP';
        }

        $lines[] = 'user='.self::quote((string) $cfg['username']);
        $lines[] = 'password='.self::quote((string) $cfg['password']);

        if (empty($cfg['ssl'])) {
            // Dua opsi, keduanya dengan awalan loose- (klien yang tidak mengenalnya hanya memberi
            // peringatan): skip-ssl mematikan TLS; ssl-verify-server-cert=0 mematikan verifikasi
            // sertifikat. Yang kedua yang menyelesaikan "self-signed certificate in certificate
            // chain" pada klien MariaDB baru yang memverifikasi secara default — terbukti
            // menggagalkan backup bila hanya skip-ssl yang dipasang.
            $lines[] = 'loose-ssl-verify-server-cert=0';
            $lines[] = 'loose-skip-ssl';
        }

        return implode("\n", $lines)."\n";
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new BackupException('Kredensial database memuat karakter yang tidak didukung (baris baru).');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @return array<string, mixed> */
    private function connectionConfig(): array
    {
        $name = config('database.default');
        $cfg = config("database.connections.{$name}");

        if (! is_array($cfg) || ! in_array($cfg['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new BackupException(
                "Backup hanya mendukung database MySQL (koneksi aktif: {$name}). "
                .'Jalankan perintah ini di dalam container aplikasi.'
            );
        }

        if (empty($cfg['database'])) {
            throw new BackupException('Nama database belum dikonfigurasi.');
        }

        return $cfg;
    }

    /** @param  array<string, mixed>  $cfg */
    private function writeOptionsFile(array $cfg): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbopt');
        if ($path === false) {
            throw new BackupException('Tidak bisa membuat file sementara untuk kredensial.');
        }

        chmod($path, 0600); // tempnam sudah 0600; ditegaskan agar tidak bergantung umask
        file_put_contents($path, self::optionsFileContents([
            'host' => $cfg['host'] ?? null,
            'port' => $cfg['port'] ?? null,
            'socket' => $cfg['unix_socket'] ?? null, // nama kunci di config/database.php Laravel
            'username' => (string) ($cfg['username'] ?? ''),
            'password' => (string) ($cfg['password'] ?? ''),
            'ssl' => (bool) config('backup.db_ssl'),
        ]));

        return $path;
    }

    /**
     * mysqldump menulis "-- Dump completed" di baris akhir hanya bila SELURUH dump
     * selesai. Tanpa ini, dump terpotong (disk penuh, koneksi putus) bisa lolos
     * sebagai "backup berhasil" padahal tidak bisa dipulihkan.
     */
    private function assertDumpComplete(string $path): void
    {
        $size = is_file($path) ? filesize($path) : 0;
        if (! $size) {
            throw new BackupException('Dump database kosong.');
        }

        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $size - 2048));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (! str_contains($tail, 'Dump completed')) {
            throw new BackupException('Dump database tidak lengkap (penanda akhir dump tidak ditemukan).');
        }
    }

    private function lastError(Process $process): string
    {
        // Baris peringatan ("Charset id ...", "[Warning] unknown option ...") hanyalah noise;
        // pesan kegagalan sebenarnya ada di baris non-peringatan.
        $lines = array_filter(
            array_map('trim', explode("\n", $process->getErrorOutput())),
            fn ($l) => $l !== '' && ! str_contains($l, 'Warning')
        );

        $last = $lines ? end($lines) : 'tanpa pesan (kode keluar '.$process->getExitCode().')';

        return mb_substr($last, 0, 300);
    }
}