<?php

namespace App\Services\Backup;

use Carbon\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Satu-satunya pintu pembuatan, verifikasi, retensi, dan restore paket backup (QA-012).
 *
 * Invarian yang dijaga di sini:
 *  - Backup ditulis ke folder sementara lalu di-RENAME (atomik) ke nama akhir setelah
 *    diverifikasi; file backup setengah jadi tidak pernah muncul di daftar.
 *  - Backup lama dihapus (retensi) HANYA setelah backup baru berhasil, dan sekian backup
 *    terbaru (keep_min) tidak pernah dihapus berapa pun umurnya.
 *  - Satu operasi pada satu waktu (flock): backup terjadwal tidak bisa menabrak restore
 *    atau backup manual.
 *  - Restore SELALU didahului backup pengaman (label "pra-restore"); bila impor gagal,
 *    dicoba pengembalian otomatis dari backup pengaman itu.
 *  - Nama file dari luar divalidasi ketat (find()) dan nama entri zip divalidasi
 *    (isSafeEntryName) — tidak ada path traversal / zip-slip.
 *  - Zona waktu nama file & tampilan eksplisit (config backup.timezone), tidak
 *    bergantung keputusan QA-008.
 */
class BackupService
{
    public const LABELS = ['auto', 'manual', 'pra-restore'];

    public const NAME_PATTERN = '/^backup-(\d{8})-(\d{6})-(auto|manual|pra-restore)\.zip$/';

    public const REQUEST_QUEUED = 'queued';

    public const REQUEST_ALREADY_PENDING = 'already_pending';

    public const REQUEST_RUNNING = 'running';

    private const FORMAT_VERSION = 1;

    private const SQL_ENTRY = 'database.sql';

    private const XLSX_ENTRY = 'arsip-data.xlsx';

    private const MANIFEST_ENTRY = 'manifest.json';

    private const STORAGE_PREFIX = 'storage-public/';

    /** Format yang sudah terkompresi: disimpan apa adanya agar CPU tidak terbuang. */
    private const STORE_ONLY_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'zip', 'pdf'];

    public function __construct(
        private DatabaseDumper $dumper,
        private ExcelArchive $excel,
    ) {
    }

    // ------------------------------------------------------------------
    // Pembuatan
    // ------------------------------------------------------------------

    /**
     * @return array{name: string, path: string, size: int, label: string, created_at: Carbon,
     *               warnings: list<string>, storage_files: int, tables: ?int, pruned: list<string>}
     *
     * @throws BackupException
     */
    public function create(string $label = 'auto'): array
    {
        if (! in_array($label, self::LABELS, true)) {
            throw new \InvalidArgumentException("Label backup tidak dikenal: {$label}");
        }

        $dir = $this->directory();
        $lock = $this->acquireLock($dir);

        try {
            return $this->createLocked($dir, $label);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array<string, mixed> */
    private function createLocked(string $dir, string $label): array
    {
        $startedAt = $this->clock();
        $tmp = null;

        try {
            $this->removeStaleTemp($dir);
            $this->assertEnoughDiskSpace($dir);

            $tmp = $dir.'/.tmp-'.bin2hex(random_bytes(6));
            if (! mkdir($tmp, 0700)) {
                throw new BackupException('Tidak bisa membuat folder kerja di dalam folder backup.');
            }

            $sqlPath = $tmp.'/'.self::SQL_ENTRY;
            $this->dumper->dump($sqlPath);
            if (! is_file($sqlPath) || filesize($sqlPath) === 0) {
                throw new BackupException('Dump database kosong.');
            }

            // Excel hanya salinan arsip: kegagalannya tidak boleh menggagalkan backup SQL.
            $warnings = [];
            $xlsxPath = $tmp.'/'.self::XLSX_ENTRY;
            try {
                $warnings = $this->excel->write($xlsxPath, $startedAt);
            } catch (\Throwable $e) {
                @unlink($xlsxPath);
                $warnings[] = 'Arsip Excel gagal dibuat: '.mb_substr($e->getMessage(), 0, 200);
                Log::warning('Backup: arsip Excel gagal', ['error' => $e->getMessage()]);
            }

            [$storageFiles, $storageBytes] = $this->collectStorageFiles($warnings);

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'created_at' => $startedAt->toIso8601String(),
                'label' => $label,
                'app' => ['name' => config('app.name'), 'env' => config('app.env')],
                'database' => [
                    'name' => config('database.connections.'.config('database.default').'.database'),
                    'tables' => $this->tableCount(),
                    'last_migration' => $this->lastMigration(),
                ],
                'files' => [
                    self::SQL_ENTRY => ['size' => filesize($sqlPath), 'sha256' => hash_file('sha256', $sqlPath)],
                    self::XLSX_ENTRY => is_file($xlsxPath)
                        ? ['size' => filesize($xlsxPath), 'sha256' => hash_file('sha256', $xlsxPath)]
                        : null,
                ],
                'storage' => ['files' => count($storageFiles), 'bytes' => $storageBytes],
                'warnings' => $warnings,
            ];
            $manifestPath = $tmp.'/'.self::MANIFEST_ENTRY;
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $zipPath = $tmp.'/package.zip';
            $this->buildZip($zipPath, $sqlPath, $manifestPath, is_file($xlsxPath) ? $xlsxPath : null, $storageFiles);
            $this->verifyArchive($zipPath);

            // Dua backup berlabel sama dalam detik yang sama (mis. tombol manual tepat setelah
            // backup manual lain) tidak boleh saling menimpa maupun dianggap gagal: stempel waktu
            // pada NAMA digeser per detik sampai unik (manifest tetap mencatat waktu sebenarnya).
            $nameTime = $startedAt->copy();
            do {
                $name = sprintf('backup-%s-%s.zip', $nameTime->format('Ymd-His'), $label);
                $final = $dir.'/'.$name;
                $taken = file_exists($final);
                if ($taken) {
                    $nameTime->addSecond();
                }
            } while ($taken);
            if (! rename($zipPath, $final)) {
                throw new BackupException('Gagal memindahkan backup ke nama akhir.');
            }
            @chmod($final, 0640);

            $this->writeStatus($dir, [
                'last_run_at' => $startedAt->toIso8601String(),
                'last_success_at' => $startedAt->toIso8601String(),
                'last_file' => $name,
                'last_error' => null,
                'last_warnings' => $warnings,
            ]);

            // Retensi dijalankan SETELAH sukses; kegagalannya tidak boleh membatalkan backup yang sudah jadi.
            try {
                $pruned = $this->prune();
            } catch (\Throwable $e) {
                $pruned = [];
                Log::warning('Backup: retensi gagal', ['error' => $e->getMessage()]);
            }

            return [
                'name' => $name,
                'path' => $final,
                'size' => (int) filesize($final),
                'label' => $label,
                'created_at' => $startedAt,
                'warnings' => $warnings,
                'storage_files' => count($storageFiles),
                'tables' => $manifest['database']['tables'],
                'pruned' => $pruned,
            ];
        } catch (\Throwable $e) {
            $this->recordFailure($dir, $e, $startedAt);

            throw $e instanceof BackupException
                ? $e
                : new BackupException('Backup gagal: '.mb_substr($e->getMessage(), 0, 300), 0, $e);
        } finally {
            if ($tmp !== null) {
                $this->deleteTree($tmp);
            }
        }
    }

    // ------------------------------------------------------------------
    // Daftar, status, retensi
    // ------------------------------------------------------------------

    /**
     * Backup yang ada, terbaru dulu.
     *
     * @return list<array{name: string, label: string, size: int, created_at: Carbon}>
     */
    public function list(): array
    {
        $dir = rtrim((string) config('backup.path'), '/\\');
        if (! is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (! preg_match(self::NAME_PATTERN, $file, $m) || ! is_file($dir.'/'.$file) || is_link($dir.'/'.$file)) {
                continue;
            }

            $items[] = [
                'name' => $file,
                'label' => $m[3],
                'size' => (int) filesize($dir.'/'.$file),
                'created_at' => Carbon::createFromFormat('Ymd His', $m[1].' '.$m[2], config('backup.timezone')),
            ];
        }

        usort($items, fn ($a, $b) => [$b['created_at']->getTimestamp(), $b['name']] <=> [$a['created_at']->getTimestamp(), $a['name']]);

        return $items;
    }

    /**
     * Path lengkap backup bernama $name, atau null. Nama divalidasi KETAT terhadap pola
     * (tidak boleh ada ../ atau subfolder) dan symlink ditolak.
     */
    public function find(string $name): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $path = rtrim((string) config('backup.path'), '/\\').'/'.$name;

        return is_file($path) && ! is_link($path) ? $path : null;
    }

    /**
     * Hapus backup melewati masa retensi, tetapi selalu sisakan keep_min terbaru.
     *
     * @return list<string> nama file yang dihapus
     */
    public function prune(): array
    {
        $cutoff = $this->clock()->subDays((int) config('backup.retention_days'));
        $keepMin = (int) config('backup.keep_min');
        $deleted = [];

        foreach (array_slice($this->list(), $keepMin) as $item) {
            if ($item['created_at']->lt($cutoff) && @unlink(rtrim((string) config('backup.path'), '/\\').'/'.$item['name'])) {
                $deleted[] = $item['name'];
            }
        }

        return $deleted;
    }

    /**
     * @return array{last_success_at: ?Carbon, last_run_at: ?Carbon, last_error: ?string, last_warnings: array,
     *               last_failure_at: ?Carbon, stale: bool, free_bytes: ?int}
     */
    public function status(): array
    {
        $dir = rtrim((string) config('backup.path'), '/\\');
        $raw = is_file($dir.'/status.json') ? json_decode((string) file_get_contents($dir.'/status.json'), true) : [];
        $raw = is_array($raw) ? $raw : [];

        $parse = fn ($v) => $v ? Carbon::parse($v)->setTimezone(config('backup.timezone')) : null;

        $lastSuccess = $parse($raw['last_success_at'] ?? null);
        if ($lastSuccess === null && ($newest = $this->list()[0] ?? null)) {
            $lastSuccess = $newest['created_at']; // backup yang dicopy manual ke folder ini
        }

        $stale = $lastSuccess === null
            || $lastSuccess->lt($this->clock()->subHours((int) config('backup.stale_after_hours')));

        return [
            'last_success_at' => $lastSuccess,
            'last_run_at' => $parse($raw['last_run_at'] ?? null),
            'last_failure_at' => $parse($raw['last_failure_at'] ?? null),
            'last_error' => $raw['last_error'] ?? null,
            'last_warnings' => $raw['last_warnings'] ?? [],
            'stale' => $stale,
            'free_bytes' => is_dir($dir) ? ($this->freeBytes($dir)) : null,
            'running' => $this->isRunning(),
            'pending_request' => $this->pendingRequest(),
            'scheduler_seen_at' => $this->heartbeatAt(),
            'scheduler_alive' => $this->schedulerAlive(),
        ];
    }

    // ------------------------------------------------------------------
    // Status berjalan, permintaan manual dari UI, detak penjadwal
    // ------------------------------------------------------------------

    /** Apakah backup/restore sedang berjalan (kunci sedang dipegang proses lain). */
    public function isRunning(): bool
    {
        $dir = rtrim((string) config('backup.path'), '/\\');
        if (! is_dir($dir)) {
            return false;
        }

        $handle = @fopen($dir.'/.lock', 'c');
        if ($handle === false) {
            return false;
        }

        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $acquired;
    }

    /**
     * Minta backup manual dari UI. Dump TIDAK dijalankan di request web (bisa kena batas waktu
     * Nginx/PHP); permintaan ditaruh sebagai file penanda lalu dikerjakan penjadwal (backup:tick).
     * Pembuatan file memakai mode 'x' (atomik): dua klik bersamaan tidak membuat dua permintaan.
     *
     * @return self::REQUEST_QUEUED|self::REQUEST_ALREADY_PENDING|self::REQUEST_RUNNING
     */
    public function requestRun(?int $userId = null): string
    {
        $dir = $this->directory();

        if ($this->isRunning()) {
            return self::REQUEST_RUNNING;
        }

        $handle = @fopen($dir.'/run-requested', 'x');
        if ($handle === false) {
            return self::REQUEST_ALREADY_PENDING;
        }

        fwrite($handle, (string) json_encode([
            'requested_at' => $this->clock()->toIso8601String(),
            'requested_by' => $userId,
        ]));
        fclose($handle);

        return self::REQUEST_QUEUED;
    }

    /** @return array<string, mixed>|null */
    public function pendingRequest(): ?array
    {
        $file = rtrim((string) config('backup.path'), '/\\').'/run-requested';
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Ambil & hapus permintaan manual (rename atomik: bila dua proses berebut, hanya satu yang menang).
     * Dihapus SEBELUM backup dijalankan supaya permintaan yang gagal tidak diulang tanpa henti.
     *
     * @return array<string, mixed>|null
     */
    public function consumeRequest(): ?array
    {
        $file = rtrim((string) config('backup.path'), '/\\').'/run-requested';
        if (! is_file($file)) {
            return null;
        }

        $taken = $file.'.taking-'.getmypid();
        if (! @rename($file, $taken)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($taken), true);
        @unlink($taken);

        return is_array($data) ? $data : [];
    }

    /** Dipanggil penjadwal tiap menit: bukti bahwa penjadwal (dan akses ke folder backup) hidup. */
    public function recordHeartbeat(): void
    {
        file_put_contents($this->directory().'/scheduler-heartbeat', $this->clock()->toIso8601String(), LOCK_EX);
    }

    public function heartbeatAt(): ?Carbon
    {
        $file = rtrim((string) config('backup.path'), '/\\').'/scheduler-heartbeat';
        if (! is_file($file)) {
            return null;
        }

        try {
            return Carbon::parse((string) file_get_contents($file))->setTimezone(config('backup.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function schedulerAlive(): bool
    {
        $seen = $this->heartbeatAt();

        return $seen !== null
            && $seen->gte($this->clock()->subMinutes((int) config('backup.scheduler_dead_after_minutes')));
    }

    /**
     * Masalah yang perlu diketahui Admin, terparah dulu. Kosong = semua sehat.
     *
     * @return list<array{level: 'danger'|'warning', message: string}>
     */
    public function issues(): array
    {
        $status = $this->status();
        $issues = [];
        $ago = fn (Carbon $t) => $t->copy()->locale('id')->diffForHumans();

        if ($status['last_success_at'] === null) {
            $issues[] = ['level' => 'danger', 'message' => 'Belum ada backup sama sekali. Data belum terlindungi.'];
        } elseif ($status['stale']) {
            $issues[] = [
                'level' => 'danger',
                'message' => 'Backup sukses terakhir '.$ago($status['last_success_at']).' (lebih dari '.config('backup.stale_after_hours').' jam).',
            ];
        }

        if ($status['last_error'] !== null) {
            $issues[] = ['level' => 'danger', 'message' => 'Backup terakhir GAGAL: '.$status['last_error']];
        }

        if (! $status['scheduler_alive']) {
            $issues[] = [
                'level' => 'warning',
                'message' => 'Penjadwal tidak aktif, sehingga backup otomatis dan tombol "Backup Sekarang" tidak akan berjalan. Periksa container toko_scheduler.',
            ];
        }

        if ($status['free_bytes'] !== null && $status['free_bytes'] < (int) config('backup.disk_warn_mb') * 1024 * 1024) {
            $issues[] = [
                'level' => 'warning',
                'message' => 'Ruang disk tempat backup hampir habis (tersisa '.Number::fileSize($status['free_bytes'], 1).').',
            ];
        }

        if ($status['last_warnings'] !== []) {
            $issues[] = ['level' => 'warning', 'message' => 'Backup terakhir selesai dengan peringatan: '.$status['last_warnings'][0]];
        }

        return $issues;
    }

    // ------------------------------------------------------------------
    // Verifikasi & restore
    // ------------------------------------------------------------------

    /**
     * Pastikan zip utuh, semua nama entri aman, manifest valid, dan checksum database.sql cocok.
     *
     * @return array<string, mixed> manifest
     *
     * @throws BackupException
     */
    public function verifyArchive(string $path): array
    {
        if (! is_file($path)) {
            throw new BackupException('File backup tidak ditemukan.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new BackupException('File backup rusak atau bukan arsip zip yang valid.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (! $this->isSafeEntryName($entry)) {
                    throw new BackupException('File backup memuat nama entri yang tidak aman dan ditolak.');
                }
            }

            $raw = $zip->getFromName(self::MANIFEST_ENTRY);
            $manifest = $raw === false ? null : json_decode($raw, true);
            if (! is_array($manifest) || ! isset($manifest['format_version'])) {
                throw new BackupException('manifest.json tidak ada atau tidak valid — ini bukan paket backup aplikasi ini.');
            }
            if ((int) $manifest['format_version'] > self::FORMAT_VERSION) {
                throw new BackupException('Backup dibuat oleh versi aplikasi yang lebih baru dan tidak bisa dibaca versi ini.');
            }

            $expected = $manifest['files'][self::SQL_ENTRY]['sha256'] ?? null;
            $stream = $zip->getStream(self::SQL_ENTRY);
            if (! is_string($expected) || $stream === false) {
                throw new BackupException('database.sql tidak ada di dalam paket backup.');
            }

            $ctx = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                hash_update($ctx, $chunk);
            }
            fclose($stream);

            if (! hash_equals($expected, hash_final($ctx))) {
                throw new BackupException('Checksum database.sql tidak cocok — file backup rusak atau telah diubah.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * Pulihkan dari $zipPath. DESTRUKTIF: seluruh isi database sekarang diganti.
     *
     * Urutan: verifikasi -> backup pengaman -> kosongkan+impor -> migrate -> pulihkan file.
     * Bila impor gagal, dicoba kembalikan dari backup pengaman.
     *
     * @return array{safety_backup: string, files_restored: int, manifest: array<string, mixed>}
     *
     * @throws BackupException
     */
    public function restore(string $zipPath): array
    {
        $manifest = $this->verifyArchive($zipPath);

        $dir = $this->directory();
        $lock = $this->acquireLock($dir);
        $tmp = null;

        try {
            $tmp = $dir.'/.tmp-'.bin2hex(random_bytes(6));
            if (! mkdir($tmp, 0700)) {
                throw new BackupException('Tidak bisa membuat folder kerja di dalam folder backup.');
            }

            $sql = $tmp.'/restore.sql';
            $this->extractEntry($zipPath, self::SQL_ENTRY, $sql);

            // Backup pengaman SEBELUM menyentuh apa pun. Bila gagal, restore dibatalkan total.
            $safety = $this->createLocked($dir, 'pra-restore');
            $safetySql = $tmp.'/safety.sql';
            $this->extractEntry($safety['path'], self::SQL_ENTRY, $safetySql);

            try {
                $this->dumper->restore($sql);
            } catch (\Throwable $e) {
                $reason = mb_substr($e->getMessage(), 0, 300);
                try {
                    $this->dumper->restore($safetySql);
                } catch (\Throwable) {
                    throw new BackupException(
                        "Restore gagal ({$reason}) DAN pengembalian otomatis juga gagal. "
                        ."Pulihkan manual dari backup pengaman: {$safety['name']}"
                    );
                }

                throw new BackupException(
                    "Restore gagal ({$reason}). Database sudah dikembalikan ke kondisi sebelum restore."
                );
            }

            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new BackupException(
                    'Data berhasil dipulihkan, tetapi migrasi lanjutan gagal. Jalankan "php artisan migrate --force" dan periksa pesannya. '
                    ."Backup pengaman tersedia: {$safety['name']}"
                );
            }

            return [
                'safety_backup' => $safety['name'],
                'files_restored' => $this->restoreFiles($zipPath),
                'manifest' => $manifest,
            ];
        } finally {
            if ($tmp !== null) {
                $this->deleteTree($tmp);
            }
            $this->releaseLock($lock);
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private function clock(): Carbon
    {
        return Carbon::now(config('backup.timezone'));
    }

    private function directory(): string
    {
        $dir = rtrim((string) config('backup.path'), '/\\');
        if ($dir === '') {
            throw new BackupException('Folder backup belum dikonfigurasi (BACKUP_PATH).');
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new BackupException("Folder backup tidak bisa dibuat: {$dir}. Periksa izin folder / volume Docker.");
        }

        if (! is_writable($dir)) {
            throw new BackupException("Folder backup tidak bisa ditulis: {$dir}. Periksa izin folder / volume Docker.");
        }

        return $dir;
    }

    /** @return resource */
    private function acquireLock(string $dir)
    {
        $handle = fopen($dir.'/.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new BackupException('Backup atau restore lain sedang berjalan. Coba lagi beberapa menit lagi.');
        }

        return $handle;
    }

    /** @param  resource  $handle */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function freeBytes(string $dir): ?int
    {
        $free = @disk_free_space($dir);

        return $free === false ? null : (int) $free;
    }

    private function assertEnoughDiskSpace(string $dir): void
    {
        $free = $this->freeBytes($dir);
        if ($free === null) {
            return; // tidak bisa ditentukan (mis. filesystem khusus): jangan menghalangi backup
        }

        $newest = $this->list()[0]['size'] ?? 0;
        $need = max((int) config('backup.min_free_mb') * 1024 * 1024, 2 * $newest);

        if ($free < $need) {
            throw new BackupException(sprintf(
                'Ruang disk tidak cukup untuk backup: tersisa %s MB, dibutuhkan minimal %s MB. Hapus backup lama atau tambah ruang disk.',
                number_format($free / 1048576, 0, ',', '.'),
                number_format($need / 1048576, 0, ',', '.')
            ));
        }
    }

    /** Folder kerja sisa proses yang mati mendadak (lebih dari 1 hari). */
    private function removeStaleTemp(string $dir): void
    {
        foreach (glob($dir.'/.tmp-*') ?: [] as $stale) {
            if (is_dir($stale) && filemtime($stale) < time() - 86400) {
                $this->deleteTree($stale);
            }
        }
    }

    /**
     * @param  list<string>  $warnings  diisi bila ada file yang dilewati
     * @return array{0: list<array{0: string, 1: string}>, 1: int} [[path, nama entri], ...], total byte
     */
    private function collectStorageFiles(array &$warnings): array
    {
        $base = rtrim((string) config('backup.storage_source'), '/\\');
        if (! is_dir($base)) {
            return [[], 0];
        }

        $files = [];
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (! $file->isReadable()) {
                $warnings[] = "File dilewati karena tidak bisa dibaca: {$relative}";

                continue;
            }

            $files[] = [$file->getPathname(), self::STORAGE_PREFIX.$relative];
            $bytes += (int) $file->getSize();
        }

        return [$files, $bytes];
    }

    /** @param  list<array{0: string, 1: string}>  $storageFiles */
    private function buildZip(string $zipPath, string $sql, string $manifest, ?string $xlsx, array $storageFiles): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new BackupException('Tidak bisa membuat arsip zip.');
        }

        $add = function (string $path, string $entry) use ($zip): void {
            if (! $zip->addFile($path, $entry)) {
                throw new BackupException("Gagal memasukkan {$entry} ke arsip.");
            }
            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::STORE_ONLY_EXTENSIONS, true)) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        };

        $add($sql, self::SQL_ENTRY);
        $add($manifest, self::MANIFEST_ENTRY);
        if ($xlsx !== null) {
            $add($xlsx, self::XLSX_ENTRY);
        }
        foreach ($storageFiles as [$path, $entry]) {
            $add($path, $entry);
        }

        // ZipArchive baru benar-benar menulis saat close(); gagal di sini biasanya disk penuh.
        if (! $zip->close()) {
            throw new BackupException('Gagal menulis arsip zip (kemungkinan ruang disk habis).');
        }
    }

    /** Hanya nama entri yang dikenal; tidak boleh keluar dari folder tujuan (zip-slip). */
    private function isSafeEntryName(string $name): bool
    {
        if (in_array($name, [self::SQL_ENTRY, self::XLSX_ENTRY, self::MANIFEST_ENTRY], true)) {
            return true;
        }

        if (! str_starts_with($name, self::STORAGE_PREFIX) || preg_match('/[\\\\:\0]/', $name)) {
            return false;
        }

        $relative = substr($name, strlen(self::STORAGE_PREFIX));
        if ($relative === '' || str_starts_with($relative, '/')) {
            return false;
        }

        foreach (explode('/', rtrim($relative, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function extractEntry(string $zipPath, string $entry, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BackupException('File backup tidak bisa dibuka.');
        }

        try {
            $in = $zip->getStream($entry);
            $out = fopen($destination, 'wb');
            if ($in === false || $out === false) {
                throw new BackupException("Gagal mengekstrak {$entry} dari backup.");
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            if (! fclose($out)) {
                throw new BackupException("Gagal menulis hasil ekstrak {$entry} (ruang disk habis?).");
            }
        } finally {
            $zip->close();
        }
    }

    /** Salin storage-public/* ke folder sumber. File yang sudah ada ditimpa; yang tidak ada di backup dibiarkan. */
    private function restoreFiles(string $zipPath): int
    {
        $base = rtrim((string) config('backup.storage_source'), '/\\');
        if (! is_dir($base) && ! @mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new BackupException("Folder file unggahan tidak bisa dibuat: {$base}");
        }
        $realBase = realpath($base);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BackupException('File backup tidak bisa dibuka.');
        }

        $count = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (! str_starts_with($entry, self::STORAGE_PREFIX) || str_ends_with($entry, '/')) {
                    continue;
                }

                $target = $base.'/'.substr($entry, strlen(self::STORAGE_PREFIX));
                $parent = dirname($target);
                if (! is_dir($parent) && ! @mkdir($parent, 0775, true) && ! is_dir($parent)) {
                    throw new BackupException("Folder tujuan file tidak bisa dibuat: {$parent}");
                }

                // Pertahanan lapis dua: walau nama entri lolos validasi, tujuan akhir harus tetap di dalam folder sumber.
                $realParent = realpath($parent);
                if ($realParent === false || ! str_starts_with($realParent.DIRECTORY_SEPARATOR, $realBase.DIRECTORY_SEPARATOR)) {
                    throw new BackupException('Tujuan file berada di luar folder unggahan dan ditolak.');
                }

                $in = $zip->getStream($entry);
                $partial = $target.'.restoring';
                $out = fopen($partial, 'wb');
                if ($in === false || $out === false) {
                    throw new BackupException("Gagal memulihkan file: {$entry}");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);

                if (! rename($partial, $target)) {
                    @unlink($partial);
                    throw new BackupException("Gagal memulihkan file: {$entry}");
                }
                @chmod($target, 0664);
                $count++;
            }
        } finally {
            $zip->close();
        }

        return $count;
    }

    /** @param  array<string, mixed>  $data */
    private function writeStatus(string $dir, array $data): void
    {
        $path = $dir.'/status.json';
        $current = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $merged = array_merge(is_array($current) ? $current : [], $data);

        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function recordFailure(string $dir, \Throwable $e, Carbon $at): void
    {
        Log::error('Backup gagal', ['error' => $e->getMessage()]);

        try {
            $this->writeStatus($dir, [
                'last_run_at' => $at->toIso8601String(),
                'last_failure_at' => $at->toIso8601String(),
                'last_error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        } catch (\Throwable) {
            // Gagal mencatat status tidak boleh menutupi kesalahan aslinya.
        }
    }

    private function tableCount(): ?int
    {
        try {
            return count(Schema::getTableListing());
        } catch (\Throwable) {
            return null;
        }
    }

    private function lastMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (\Throwable) {
            return null;
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            ($item->isDir() && ! $item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}