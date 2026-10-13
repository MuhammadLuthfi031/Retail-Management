@php
    use Illuminate\Support\Number;

    $labelBadge = [
        'auto' => ['Otomatis', 'bg-gray-100 text-gray-700'],
        'manual' => ['Manual', 'bg-indigo-100 text-indigo-700'],
        'pra-restore' => ['Pengaman restore', 'bg-amber-100 text-amber-700'],
    ];
    $issueClass = [
        'danger' => 'bg-red-50 border-red-200 text-red-800',
        'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
    ];
    $busy = $status['running'] || $status['pending_request'];
    $last = $status['last_success_at'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Backup Data</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            @foreach ($issues as $issue)
                <div class="rounded-lg border px-4 py-3 text-sm {{ $issueClass[$issue['level']] }}">
                    {{ $issue['message'] }}
                </div>
            @endforeach

            <!-- Status & aksi -->
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <div>
                        <div class="text-xs text-gray-400">Backup sukses terakhir</div>
                        @if ($last)
                            <div class="text-sm font-semibold text-gray-900 mt-0.5">{{ $last->format('d M Y, H:i') }} WIB</div>
                            <div class="text-xs text-gray-500">{{ $last->copy()->locale('id')->diffForHumans() }}</div>
                        @else
                            <div class="text-sm font-semibold text-red-600 mt-0.5">Belum ada</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Backup otomatis</div>
                        <div class="text-sm font-semibold text-gray-900 mt-0.5">Tiap hari pukul {{ $scheduleAt }} WIB</div>
                        <div class="text-xs text-gray-500">Disimpan {{ $retentionDays }} hari terakhir</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Penjadwal</div>
                        @if ($status['scheduler_alive'])
                            <div class="text-sm font-semibold text-emerald-700 mt-0.5">Aktif</div>
                        @else
                            <div class="text-sm font-semibold text-red-600 mt-0.5">Tidak aktif</div>
                        @endif
                        <div class="text-xs text-gray-500">
                            {{ $status['scheduler_seen_at'] ? 'Terakhir aktif '.$status['scheduler_seen_at']->copy()->locale('id')->diffForHumans() : 'Belum pernah terdeteksi' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Ruang disk tersisa</div>
                        <div class="text-sm font-semibold text-gray-900 mt-0.5">
                            {{ $status['free_bytes'] !== null ? Number::fileSize($status['free_bytes'], 1) : 'Tidak diketahui' }}
                        </div>
                    </div>
                </div>

                <div class="mt-5 pt-5 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
                    <form method="POST" action="{{ route('admin.backup.store') }}">
                        @csrf
                        <button type="submit" @disabled($busy)
                                class="min-h-[44px] px-5 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                            {{ $status['running'] ? 'Backup sedang berjalan...' : ($status['pending_request'] ? 'Menunggu penjadwal...' : 'Backup Sekarang') }}
                        </button>
                    </form>
                    <p class="text-xs text-gray-500">Backup dikerjakan di latar belakang dan biasanya selesai dalam 1–2 menit. Kasir tetap bisa bertransaksi selama backup berjalan.</p>
                </div>
            </div>

            <!-- Daftar backup -->
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-900">Daftar backup</h3>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse ($items as $item)
                        <div class="px-5 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-900">{{ $item['created_at']->format('d M Y, H:i') }} WIB</div>
                                <div class="text-xs text-gray-500 mt-0.5 break-all font-mono">{{ $item['name'] }}</div>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $labelBadge[$item['label']][1] }}">{{ $labelBadge[$item['label']][0] }}</span>
                                    <span class="text-xs text-gray-500">{{ Number::fileSize($item['size'], 1) }}</span>
                                </div>
                            </div>
                            <a href="{{ route('admin.backup.download', $item['name']) }}"
                               class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100">
                                Unduh
                            </a>
                        </div>
                    @empty
                        <div class="p-8 text-center text-sm text-gray-400">Belum ada backup. Tekan "Backup Sekarang" untuk membuat yang pertama.</div>
                    @endforelse
                </div>
            </div>

            <!-- Panduan -->
            <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3 text-sm text-gray-600">
                <h3 class="font-semibold text-gray-900">Penting diketahui</h3>
                <p><span class="font-medium text-gray-800">Simpan salinan di luar komputer toko.</span> Backup yang hanya ada di komputer yang sama ikut hilang bila disk rusak. Unduh backup terbaru secara berkala ke flashdisk atau komputer lain.</p>
                <p><span class="font-medium text-gray-800">Jaga kerahasiaan file backup.</span> Isinya seluruh data toko, termasuk data akun pengguna. Jangan dikirim lewat chat atau email yang tidak aman.</p>
                <p><span class="font-medium text-gray-800">Pemulihan hanya lewat terminal</span> oleh teknisi, karena menimpa seluruh data sekarang. Perintahnya:</p>
                <p class="font-mono text-xs bg-gray-50 border border-gray-200 rounded-md px-3 py-2 break-all">docker compose exec app php artisan backup:restore NAMA-FILE.zip</p>
            </div>
        </div>
    </div>

    @if ($busy)
        <script>
            // Ada backup berjalan / menunggu: muat ulang berkala sampai selesai.
            setTimeout(function () { window.location.reload(); }, 8000);
        </script>
    @endif
</x-app-layout>