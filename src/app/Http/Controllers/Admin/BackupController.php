<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman Backup — HANYA Admin (isi backup = seluruh data toko termasuk hash password).
 *
 * Sengaja TIDAK ada restore dan hapus di sini:
 *  - Restore menimpa seluruh data; satu klik salah (atau akun yang disalahgunakan) bisa
 *    menghapus data hari ini. Restore hanya lewat terminal: `php artisan backup:restore`.
 *  - Penghapusan ditangani retensi otomatis.
 *
 * "Backup Sekarang" tidak menjalankan dump di request web (rawan kena batas waktu): hanya
 * menaruh permintaan yang dikerjakan penjadwal dalam ±1 menit (lihat BackupTick).
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups)
    {
    }

    public function index(): View
    {
        return view('admin.backup.index', [
            'status' => $this->backups->status(),
            'issues' => $this->backups->issues(),
            'items' => $this->backups->list(),
            'scheduleAt' => (string) config('backup.schedule_at'),
            'retentionDays' => (int) config('backup.retention_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $result = $this->backups->requestRun($request->user()->id);
        } catch (BackupException $e) {
            return back()->with('error', $e->getMessage());
        }

        return match ($result) {
            BackupService::REQUEST_QUEUED => back()->with('success', 'Backup dijadwalkan dan akan selesai dalam sekitar 1 menit. Halaman ini memperbarui diri otomatis.'),
            BackupService::REQUEST_RUNNING => back()->with('success', 'Backup sedang berjalan. Tunggu sampai selesai.'),
            default => back()->with('success', 'Permintaan backup sudah dalam antrean. Tunggu sekitar 1 menit.'),
        };
    }

    /**
     * Nama divalidasi dua lapis: pola di route (->where) dan BackupService::find() (pola ketat,
     * tanpa subfolder, tolak symlink). Tidak ada nama dari pengguna yang dipakai sebagai path mentah.
     */
    public function download(Request $request, string $name): BinaryFileResponse
    {
        $path = $this->backups->find($name);
        abort_if($path === null, 404);

        Log::info('Backup diunduh', ['user_id' => $request->user()->id, 'file' => $name]);

        return response()->download($path, $name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}