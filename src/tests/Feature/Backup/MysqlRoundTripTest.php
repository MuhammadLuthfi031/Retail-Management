<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlDumper;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsRetailData;
use Tests\Support\UsesBackupSandbox;
use Tests\TestCase;

/**
 * Dump & restore SUNGGUHAN ke MySQL lewat klien baris-perintah — satu-satunya test yang
 * membuktikan paket backup benar-benar bisa dipulihkan.
 *
 * HANYA berjalan bila DB_CONNECTION=mysql dan nama database mengandung "test"
 * (DatabaseMigrations menjalankan migrate:fresh = MENGHAPUS isi database itu). Pada suite biasa (SQLite)
 * dilewati otomatis.
 *
 * Contoh:  DB_CONNECTION=mysql DB_DATABASE=toko_test php artisan test tests/Feature/Backup/MysqlRoundTripTest.php
 * Nama database WAJIB mengandung "test"; selain itu test dilewati (pagar pengaman).
 *
 * Tidak memakai RefreshDatabase: itu membungkus test dalam transaksi, sehingga data
 * tidak terlihat oleh proses mysqldump yang memakai koneksi lain.
 */
class MysqlRoundTripTest extends TestCase
{
    use BuildsRetailData;
    use DatabaseMigrations;
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('Test ini hanya berjalan dengan DB_CONNECTION=mysql.');
        }

        // DatabaseMigrations menjalankan migrate:fresh = MENGHAPUS seluruh isi database.
        // Pagar pengaman: tolak database yang namanya tidak jelas-jelas database uji, supaya
        // salah ketik DB_DATABASE=toko_db tidak menghapus data kerja.
        if (! str_contains(strtolower((string) getenv('DB_DATABASE')), 'test')) {
            $this->markTestSkipped('Ditolak demi keamanan: nama database uji harus mengandung "test" (mis. toko_test). Test ini MENGHAPUS isi database.');
        }

        parent::setUp();
        $this->setUpBackupSandbox();

        // setUpBackupSandbox memasang dumper palsu; di sini yang diuji justru yang NYATA.
        $this->app->bind(DatabaseDumper::class, MysqlDumper::class);
        $this->backups = $this->app->make(BackupService::class);
    }

    protected function tearDown(): void
    {
        // Bila test dilewati di setUp (bukan MySQL), aplikasi belum pernah dibuat.
        if ($this->app !== null) {
            Artisan::call('up');
        }
        if (isset($this->sandbox)) {
            $this->tearDownBackupSandbox();
        }
        parent::tearDown();
    }

    /** @return array<string, array> isi semua tabel bisnis + migrations, urut id. */
    private function snapshot(): array
    {
        $tables = array_merge(array_keys(config('backup.excel_tables')), ['migrations']);
        $snap = [];
        foreach ($tables as $t) {
            $snap[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $snap;
    }

    private function seedBusinessData(): void
    {
        User::factory()->admin()->create(['name' => "Admin Ünïcödé 日本 😀"]);

        $tricky = "Kopi 'Luwak' \"Premium\" \\ backslash é 日本 😀 ; DROP TABLE x; --";
        $p1 = $this->makeProduct(['name' => $tricky, 'description' => "baris1\nbaris2\ttab"]);
        $p2 = $this->makeProduct(['name' => 'Beras Curah']);
        DB::table('products')->where('id', $p2->id)->update(['stock' => 12.345, 'average_cost' => 9876.5432]);

        $this->makeTransaction(['grand_total' => 12500], [
            ['product_id' => $p1->id, 'product_name' => $tricky, 'price' => 5000, 'quantity' => 2.5, 'subtotal' => 12500],
        ]);
        $this->makePurchaseOrder([], [['product' => $p2]]);

        $this->putUpload('products/foto.jpg', random_bytes(300));   // biner acak: harus kembali PERSIS
    }

    public function test_backup_lalu_restore_mengembalikan_semua_data_dan_file_persis_sama(): void
    {
        $this->seedBusinessData();
        $before = $this->snapshot();
        $fileBefore = file_get_contents($this->uploadDir.'/products/foto.jpg');

        $info = $this->backups->create();

        // Rusak-kan: ubah data, hapus data, tambah tabel asing, ubah & hapus file.
        DB::table('products')->update(['name' => 'RUSAK']);
        DB::table('transaction_details')->delete();
        DB::table('users')->update(['name' => 'DIUBAH']);
        DB::statement('CREATE TABLE zzz_asing (id INT)');
        DB::table('zzz_asing')->insert(['id' => 1]);
        file_put_contents($this->uploadDir.'/products/foto.jpg', 'RUSAK');
        $this->assertNotSame($before, $this->snapshot(), 'prasyarat: data memang sudah rusak');

        $result = $this->backups->restore($info['path']);

        $this->assertSame($before, $this->snapshot(), 'seluruh isi tabel harus identik dengan saat backup');
        $this->assertFalse(Schema::hasTable('zzz_asing'), 'tabel yang tidak ada di backup harus hilang');
        $this->assertSame($fileBefore, file_get_contents($this->uploadDir.'/products/foto.jpg'), 'file biner kembali byte-per-byte');
        $this->assertSame(1, $result['files_restored']);
        $this->assertFileExists($this->backupDir.'/'.$result['safety_backup']);
    }

    public function test_backup_pengaman_berisi_kondisi_sebelum_restore_dan_bisa_dipulihkan(): void
    {
        $this->seedBusinessData();
        $original = $this->backups->create();

        DB::table('categories')->update(['name' => 'KONDISI-SEBELUM-RESTORE']);
        $afterChange = $this->snapshot();

        $result = $this->backups->restore($original['path']);
        $this->assertNotSame($afterChange, $this->snapshot());

        // "Oops, saya salah pilih backup" -> pulihkan dari backup pengaman.
        $this->backups->restore($this->backupDir.'/'.$result['safety_backup']);

        $this->assertSame($afterChange, $this->snapshot(), 'backup pengaman = kondisi tepat sebelum restore pertama');
    }

    public function test_restore_backup_lama_lalu_migrasi_yang_lebih_baru_menyusul(): void
    {
        // Backup dibuat saat migrasi terakhir BELUM ada...
        $newest = DB::table('migrations')->orderByDesc('id')->value('migration');
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertNotContains($newest, DB::table('migrations')->pluck('migration')->all(), 'prasyarat');
        $old = $this->backups->create();

        // ...lalu kode maju dan migrasinya dijalankan.
        Artisan::call('migrate', ['--force' => true]);
        $this->assertContains($newest, DB::table('migrations')->pluck('migration')->all());

        $this->backups->restore($old['path']);

        $this->assertContains($newest, DB::table('migrations')->pluck('migration')->all(), 'migrate harus menyusul setelah restore');
        $this->assertSame(0, Artisan::call('migrate:status') === 0 ? 0 : 1);
    }

    public function test_dump_mengandung_penanda_selesai_dan_arsip_excel_terbaca_dari_mysql(): void
    {
        $this->seedBusinessData();

        $info = $this->backups->create();
        $sql = $this->zipRead($info['path'], 'database.sql');
        $sheets = $this->readXlsxFromBackup($info['path']);

        $this->assertStringContainsString('Dump completed', $sql);
        $this->assertStringContainsString('CREATE TABLE `products`', $sql);
        $header = $sheets['Produk'][0];
        $names = array_column($sheets['Produk'], array_search('name', $header));
        $this->assertContains("Kopi 'Luwak' \"Premium\" \\ backslash é 日本 😀 ; DROP TABLE x; --", $names);
        $this->assertSame([], $info['warnings']);
    }
}