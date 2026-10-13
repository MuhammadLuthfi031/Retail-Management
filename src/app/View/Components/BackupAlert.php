<?php

namespace App\View\Components;

use App\Services\Backup\BackupService;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Banner masalah backup di dashboard Admin. Tidak menampilkan apa pun bila semuanya sehat,
 * dan tidak pernah melempar error: dashboard tidak boleh rusak hanya karena folder backup bermasalah.
 */
class BackupAlert extends Component
{
    /** @var list<array{level: string, message: string}> */
    public array $issues = [];

    public function __construct(BackupService $backups)
    {
        try {
            $this->issues = $backups->issues();
        } catch (\Throwable) {
            $this->issues = [['level' => 'warning', 'message' => 'Status backup tidak bisa dibaca. Periksa folder backup.']];
        }
    }

    public function render(): View
    {
        return view('components.backup-alert');
    }
}