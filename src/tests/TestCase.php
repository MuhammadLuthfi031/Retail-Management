<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pengaman: test memakai RefreshDatabase yang MENGHAPUS seluruh tabel. Di Docker,
     * docker-compose.yml mengisi DB_CONNECTION=mysql & DB_DATABASE=toko_db langsung ke
     * environment container, dan nilai SQLite di phpunit.xml TIDAK menimpanya (tanpa
     * force="true") — sehingga `php artisan test` diam-diam berjalan di database kerja.
     *
     * Dipasang di createApplication(), yang dipanggil SEBELUM RefreshDatabase menyentuh
     * database, jadi pengaman ini masih sempat menghentikan test.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $koneksi = (string) $config->get('database.default');

        self::pastikanDatabaseUji(
            (string) $config->get("database.connections.{$koneksi}.driver"),
            (string) $config->get("database.connections.{$koneksi}.database"),
        );

        return $app;
    }

    /**
     * Boleh: SQLite :memory:, atau database mana pun yang namanya mengandung "test"
     * (mis. toko_test — sama dengan aturan test MySQL opt-in di MysqlRoundTripTest).
     *
     * @throws RuntimeException bila yang dituju tampak seperti database kerja
     */
    public static function pastikanDatabaseUji(string $driver, string $database): void
    {
        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if (str_contains(strtolower($database), 'test')) {
            return;
        }

        throw new RuntimeException(
            "TEST DIHENTIKAN: akan berjalan di database \"{$database}\" (driver {$driver}) yang bukan "
            .'database uji — test ini MENGHAPUS seluruh isinya. Jalankan lewat: '
            .'`docker compose exec app composer test` (SQLite :memory:), atau arahkan ke database '
            .'yang namanya mengandung "test", mis. DB_DATABASE=toko_test.'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Layout memanggil @vite(...). Tanpa ini, setiap test yang merender
        // halaman akan error "Vite manifest not found" kecuali `npm run build`
        // sudah dijalankan dulu — tidak relevan untuk menguji logika backend.
        $this->withoutVite();
    }
}