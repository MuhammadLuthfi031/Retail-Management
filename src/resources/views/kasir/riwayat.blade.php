@php
    $paymentLabel = ['cash' => 'Cash', 'debit' => 'Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer'];
    $paymentColor = [
        'cash' => 'bg-emerald-100 text-emerald-700',
        'debit' => 'bg-blue-100 text-blue-700',
        'qris' => 'bg-purple-100 text-purple-700',
        'transfer' => 'bg-amber-100 text-amber-700',
    ];

    // Rentang tanggal untuk chip cepat mobile (Hari ini/7 Hari/Bulan Ini).
    // Dihitung di view, TIDAK butuh perubahan controller — cuma menghasilkan
    // query string 'dari'/'sampai' yang sama persis seperti kalau user isi
    // manual, jadi RiwayatController::index() tidak perlu disentuh sama sekali.
    $todayDate = now()->toDateString();
    $sevenDaysAgoDate = now()->subDays(6)->toDateString();
    $monthStartDate = now()->startOfMonth()->toDateString();

    $activeDari = $filters['dari'] ?? null;
    $activeSampai = $filters['sampai'] ?? null;
    $isToday = $activeDari === $todayDate && $activeSampai === $todayDate;
    $is7Hari = $activeDari === $sevenDaysAgoDate && $activeSampai === $todayDate;
    $isBulanIni = $activeDari === $monthStartDate && $activeSampai === $todayDate;
    $isCustomRange = ($activeDari || $activeSampai) && ! $isToday && ! $is7Hari && ! $isBulanIni;

    $chipOn = 'bg-indigo-600 border-indigo-600 text-white';
    $chipOff = 'bg-white border-gray-300 text-gray-600 hover:border-indigo-400';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Riwayat Transaksi Saya</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-alert />

            <!-- Filter — desktop/tablet (≥768px), tidak berubah -->
            <form method="GET" class="hidden md:flex mb-4 bg-white p-4 rounded-lg shadow-sm flex-wrap gap-3 items-end">
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Dari Tanggal</label>
                    <input type="date" name="dari" value="{{ $filters['dari'] ?? '' }}"
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="min-w-[160px]">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Sampai Tanggal</label>
                    <input type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}"
                           class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-md text-sm font-medium text-gray-700">Filter</button>
                @if (request()->anyFilled(['dari', 'sampai']))
                    <a href="{{ route('kasir.riwayat') }}" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">Reset</a>
                @endif
            </form>

            <!-- Filter — mobile (<768px): chip cepat + tanggal manual yang bisa dilipat -->
            <div class="md:hidden mb-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs text-gray-400">{{ $transactions->total() }} transaksi{{ request()->anyFilled(['dari', 'sampai']) ? ' pada rentang ini' : '' }}</span>
                    @if (request()->anyFilled(['dari', 'sampai']))
                        <a href="{{ route('kasir.riwayat') }}" class="text-xs font-medium text-gray-400 hover:text-gray-600">Reset</a>
                    @endif
                </div>

                <div class="flex gap-2 overflow-x-auto pb-1">
                    <a href="{{ route('kasir.riwayat', ['dari' => $todayDate, 'sampai' => $todayDate]) }}"
                       class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-sm border {{ $isToday ? $chipOn : $chipOff }}">
                        Hari ini
                    </a>
                    <a href="{{ route('kasir.riwayat', ['dari' => $sevenDaysAgoDate, 'sampai' => $todayDate]) }}"
                       class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-sm border {{ $is7Hari ? $chipOn : $chipOff }}">
                        7 Hari
                    </a>
                    <a href="{{ route('kasir.riwayat', ['dari' => $monthStartDate, 'sampai' => $todayDate]) }}"
                       class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-sm border {{ $isBulanIni ? $chipOn : $chipOff }}">
                        Bulan ini
                    </a>
                    <button type="button" data-collapse-toggle="filter-riwayat-mobile"
                            class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-sm border flex items-center gap-1 {{ $isCustomRange ? $chipOn : $chipOff }}">
                        Pilih tanggal
                        <x-icon name="chevron-down" class="w-3.5 h-3.5 transition-transform {{ $isCustomRange ? 'rotate-180' : '' }}" data-collapse-chevron="filter-riwayat-mobile" />
                    </button>
                </div>

                <form method="GET" id="filter-riwayat-mobile" class="{{ $isCustomRange ? '' : 'hidden' }} mt-2 bg-white border border-gray-200 rounded-lg p-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Dari Tanggal</label>
                            <input type="date" name="dari" value="{{ $filters['dari'] ?? '' }}"
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Sampai Tanggal</label>
                            <input type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}"
                                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <button type="submit" class="w-full min-h-[44px] px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">
                        Terapkan
                    </button>
                </form>
            </div>

            <!-- ====== Desktop/tablet: tabel (≥768px) ====== -->
            <div class="hidden md:block bg-white shadow-sm sm:rounded-lg overflow-hidden overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">No. Invoice</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-500 uppercase tracking-wider">Bayar</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($transactions as $trx)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $trx->invoice_number }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $trx->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3 text-center text-gray-500">{{ $trx->details_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium {{ $paymentColor[$trx->payment_method] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $paymentLabel[$trx->payment_method] ?? $trx->payment_method }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-900 font-medium">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('kasir.riwayat.struk', $trx) }}" target="_blank"
                                       class="text-indigo-600 hover:text-indigo-900 font-medium">Cetak Ulang</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                                    Belum ada transaksi{{ request()->anyFilled(['dari', 'sampai']) ? ' yang cocok dengan filter ini' : '' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- ====== Mobile: kartu (<768px) ====== -->
            <div class="md:hidden space-y-2.5">
                @forelse ($transactions as $trx)
                    <div class="bg-white border border-gray-200 rounded-xl p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 text-sm truncate">{{ $trx->invoice_number }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    {{ $trx->created_at->format('d M Y, H:i') }} &middot; {{ $trx->details_count }} item
                                </div>
                            </div>
                            <span @class([
                                'inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap flex-none',
                                $paymentColor[$trx->payment_method] ?? 'bg-gray-100 text-gray-600',
                            ])>
                                {{ $paymentLabel[$trx->payment_method] ?? $trx->payment_method }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t border-dashed border-gray-200">
                            <div class="text-base font-bold text-gray-900">
                                Rp {{ number_format($trx->grand_total, 0, ',', '.') }}
                            </div>
                            <a href="{{ route('kasir.riwayat.struk', $trx) }}" target="_blank"
                               class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100">
                                Cetak Ulang
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-gray-200 rounded-xl py-10 text-center text-gray-400 text-sm">
                        Belum ada transaksi{{ request()->anyFilled(['dari', 'sampai']) ? ' yang cocok dengan filter ini' : '' }}.
                    </div>
                @endforelse
            </div>

            <div class="mt-4">{{ $transactions->links() }}</div>
        </div>
    </div>
</x-app-layout>