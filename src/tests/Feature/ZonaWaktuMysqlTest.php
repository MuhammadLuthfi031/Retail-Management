<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-008 — PHP dan MySQL harus sepakat tentang DETIK yang sama. Hanya bermakna di MySQL
 * (SQLite menyimpan teks apa adanya, tanpa konversi zona waktu), jadi dilewati selain itu.
 *
 * Contoh:  DB_CONNECTION=mysql DB_DATABASE=toko_test php artisan test tests/Feature/ZonaWaktuMysqlTest.php
 * Nama database WAJIB mengandung "test" (RefreshDatabase menjalankan migrate:fresh = MENGHAPUS isi database).
 */
class ZonaWaktuMysqlTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('Test ini hanya berjalan dengan DB_CONNECTION=mysql.');
        }
        if (! str_contains(strtolower((string) getenv('DB_DATABASE')), 'test')) {
            $this->markTestSkipped('Ditolak demi keamanan: nama database uji harus mengandung "test". Test ini MENGHAPUS isi database.');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->app !== null) {
            parent::tearDown();
        }
    }

    public function test_sesi_mysql_memakai_zona_waktu_wib(): void
    {
        $this->assertSame('+07:00', DB::selectOne('SELECT @@session.time_zone AS tz')->tz);
    }

    public function test_php_dan_mysql_menyepakati_detik_yang_sama_untuk_kolom_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 06:30:00', 'Asia/Jakarta'));   // = 2026-10-01 23:30:00 UTC
        $category = $this->makeCategory('Uji Zona Waktu');

        $row = DB::selectOne('SELECT created_at, DATE(created_at) AS tanggal, UNIX_TIMESTAMP(created_at) AS detik FROM categories WHERE id = ?', [$category->id]);

        $this->assertSame('2026-10-02 06:30:00', $row->created_at, 'jam dinding yang tersimpan/terbaca = WIB');
        $this->assertSame('2026-10-02', $row->tanggal, 'DATE() di MySQL memberi tanggal WIB');
        $this->assertSame(
            Carbon::parse('2026-10-01 23:30:00', 'UTC')->timestamp,
            (int) $row->detik,
            'instan absolut harus sama di PHP dan MySQL; selisih 25200 detik = zona waktu tidak sinkron'
        );
    }
}