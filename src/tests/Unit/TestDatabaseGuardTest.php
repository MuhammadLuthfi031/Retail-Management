<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\TestCase as AppTestCase;

/**
 * Pengaman agar test tidak pernah menghapus database kerja (lihat Tests\TestCase).
 * Memakai PHPUnit TestCase polos: tidak boleh menyentuh database sama sekali.
 */
class TestDatabaseGuardTest extends TestCase
{
    public static function amanDipakai(): array
    {
        return [
            'sqlite memori' => ['sqlite', ':memory:'],
            'mysql toko_test' => ['mysql', 'toko_test'],
            'mysql huruf besar' => ['mysql', 'TOKO_TEST'],
            'mariadb test' => ['mariadb', 'test'],
        ];
    }

    public static function berbahaya(): array
    {
        return [
            'mysql toko_db (nama bawaan docker)' => ['mysql', 'toko_db'],
            'mysql laravel' => ['mysql', 'laravel'],
            'sqlite file kerja' => ['sqlite', '/var/www/database/database.sqlite'],
            'nama kosong' => ['mysql', ''],
        ];
    }

    #[DataProvider('amanDipakai')]
    public function test_database_uji_diizinkan(string $driver, string $db): void
    {
        AppTestCase::pastikanDatabaseUji($driver, $db);

        $this->assertTrue(true);
    }

    #[DataProvider('berbahaya')]
    public function test_database_kerja_ditolak_dengan_pesan_yang_menunjukkan_jalan_keluar(string $driver, string $db): void
    {
        try {
            AppTestCase::pastikanDatabaseUji($driver, $db);
            $this->fail('Seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TEST DIHENTIKAN', $e->getMessage());
            $this->assertStringContainsString('composer test', $e->getMessage());
        }
    }
}