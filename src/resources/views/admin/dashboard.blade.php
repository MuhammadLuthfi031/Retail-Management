<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-alert />

            <!-- ================= KPI HARI INI ================= -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-lg shadow-sm p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <x-icon name="cart" class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">Omzet Hari Ini</div>
                            <div class="text-xl font-semibold text-gray-900">Rp {{ number_format($kpi['omzet'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <x-icon name="archive" class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">Transaksi Hari Ini</div>
                            <div class="text-xl font-semibold text-gray-900">{{ number_format($kpi['jumlah_transaksi']) }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                            <x-icon name="chart" class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">Rata-rata / Transaksi</div>
                            <div class="text-xl font-semibold text-gray-900">Rp {{ number_format($kpi['rata_rata'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                <a href="#stok-menipis" class="bg-white rounded-lg shadow-sm p-5 hover:shadow-md transition block">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-red-50 text-red-600 flex items-center justify-center">
                            <x-icon name="cube" class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">Produk Stok Menipis</div>
                            <div class="text-xl font-semibold text-gray-900">{{ number_format($kpi['stok_menipis_count']) }}</div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- ================= TOGGLE PERIODE ================= -->
            <div class="flex items-center justify-between">
                <p class="text-xs text-gray-400">Grafik &amp; produk terlaris di bawah mengikuti rentang waktu ini. KPI di atas &amp; riwayat transaksi selalu "hari ini" / "terbaru".</p>
                <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden text-sm">
                    <a href="?days=7" @class(['px-3 py-1.5', 'bg-indigo-600 text-white' => $days === 7, 'bg-white text-gray-600 hover:bg-gray-50' => $days !== 7])>7 Hari</a>
                    <a href="?days=30" @class(['px-3 py-1.5 border-l border-gray-200', 'bg-indigo-600 text-white' => $days === 30, 'bg-white text-gray-600 hover:bg-gray-50' => $days !== 30])>30 Hari</a>
                </div>
            </div>

            <!-- ================= GRAFIK ================= -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-lg shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Tren Penjualan ({{ $days }} Hari Terakhir)</h3>
                    <canvas id="chart-penjualan" height="220"></canvas>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Tren Barang Masuk — Nilai Pembelian ({{ $days }} Hari Terakhir)</h3>
                    <canvas id="chart-barang-masuk" height="220"></canvas>
                </div>
            </div>

            <!-- ================= PRODUK TERLARIS & RIWAYAT ================= -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-700">Produk Terlaris ({{ $days }} Hari Terakhir)</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($produkTerlaris as $i => $p)
                            <div class="px-5 py-3 flex items-center justify-between text-sm">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-xs text-gray-300 font-mono w-4 shrink-0">{{ $i + 1 }}</span>
                                    <span class="text-gray-800 truncate">{{ $p->product_name }}</span>
                                </div>
                                <span class="text-gray-900 font-medium whitespace-nowrap">Rp {{ number_format($p->total_omzet, 0, ',', '.') }}</span>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-gray-400 text-sm">Belum ada penjualan di periode ini.</div>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Riwayat Transaksi Terbaru</h3>
                        <a href="{{ route('kasir.riwayat') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Lihat semua</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse ($riwayatTerbaru as $trx)
                            <div class="px-5 py-3 flex items-center justify-between text-sm">
                                <div class="min-w-0">
                                    <div class="text-gray-800 font-medium">{{ $trx->invoice_number }}</div>
                                    <div class="text-xs text-gray-400">{{ $trx->user->name ?? '-' }} &middot; {{ $trx->created_at->format('d M H:i') }}</div>
                                </div>
                                <span class="text-gray-900 font-medium whitespace-nowrap">Rp {{ number_format($trx->grand_total, 0, ',', '.') }}</span>
                            </div>
                        @empty
                            <div class="px-5 py-8 text-center text-gray-400 text-sm">Belum ada transaksi.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- ================= STOK MENIPIS ================= -->
            <div id="stok-menipis" class="bg-white rounded-lg shadow-sm overflow-hidden scroll-mt-6">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700">Produk Stok Menipis</h3>
                    <a href="{{ route('kasir.produk', ['low_stock' => 1]) }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Lihat semua</a>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($stokMenipis as $product)
                        <div class="px-5 py-3 flex items-center justify-between text-sm">
                            <span class="text-gray-800">{{ $product->name }}</span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                {{ $product->formatStock((float) $product->stock) }}
                            </span>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-gray-400 text-sm">Tidak ada produk dengan stok menipis. 🎉</div>
                    @endforelse
                    @if ($kpi['stok_menipis_count'] > $stokMenipis->count())
                        <div class="px-5 py-2 text-center text-xs text-gray-400">
                            +{{ $kpi['stok_menipis_count'] - $stokMenipis->count() }} produk lainnya, lihat semua untuk daftar lengkap.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="dashboard-chart-data">{!! json_encode([
        'penjualan' => $grafikPenjualan,
        'barangMasuk' => $grafikBarangMasuk,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</x-app-layout>
