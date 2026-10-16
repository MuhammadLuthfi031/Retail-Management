<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\MysqlDumper;
use Tests\TestCase;

/**
 * Perilaku MysqlDumper yang bisa diuji TANPA server MySQL: penyusunan argumen, penanganan
 * kredensial, dan penolakan hasil buruk. Memakai skrip shell palsu sebagai pengganti
 * `mysqldump`. Dump & restore sungguhan ke MySQL 8 diuji di MysqlRoundTripTest.
 */
class MysqlDumperTest extends TestCase
{
    private string $dir;

    private const PASSWORD = 'p@ss#"w\\rd';

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Memakai skrip shell; aplikasi berjalan di container Linux.');
        }

        $this->dir = sys_get_temp_dir().'/dumper-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'toko_db',
                'username' => 'toko_user', 'password' => self::PASSWORD,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function fakeBinary(string $body): void
    {
        $path = $this->dir.'/fake-dump';
        // Normalisasi CRLF -> LF: bila file test ini tersimpan dengan line ending Windows, isi
        // heredoc ikut ber-CRLF dan /bin/sh gagal ("Syntax error: word unexpected (expecting do)").
        file_put_contents($path, "#!/bin/sh\n".str_replace("\r\n", "\n", $body));
        chmod($path, 0755);
        config(['backup.dump_binary' => $path, 'backup.timeout' => 20]);
    }

    /** Skrip yang mencatat argumen, salinan & izin file opsi, lalu menulis dump. */
    private function recordingBinary(string $dumpBody = 'echo "-- Dump completed on x" > "$out"'): void
    {
        $d = $this->dir;
        $this->fakeBinary(<<<SH
printf '%s\\n' "\$@" > {$d}/ARGS
for a in "\$@"; do
  case "\$a" in
    --result-file=*) out="\${a#--result-file=}" ;;
    --defaults-extra-file=*)
      f="\${a#--defaults-extra-file=}"
      cp "\$f" {$d}/OPTS
      stat -c %a "\$f" > {$d}/PERMS
      echo "\$f" > {$d}/OPTPATH ;;
  esac
done
{$dumpBody}
SH);
    }

    public function test_dump_memakai_argumen_yang_benar_dan_password_tidak_ada_di_argumen(): void
    {
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $args = file($this->dir.'/ARGS', FILE_IGNORE_NEW_LINES);
        $this->assertStringStartsWith('--defaults-extra-file=', $args[0], 'harus argumen PERTAMA');
        foreach (['--single-transaction', '--routines', '--triggers', '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4'] as $flag) {
            $this->assertContains($flag, $args);
        }
        $this->assertSame('toko_db', end($args));
        $this->assertStringNotContainsString('p@ss', implode(' ', $args), 'password tidak boleh muncul di argumen (terlihat di ps)');
        $this->assertFileExists($this->dir.'/out.sql');
    }

    public function test_file_opsi_berizin_0600_berisi_kredensial_dan_dihapus_sesudahnya(): void
    {
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $this->assertSame('600', trim(file_get_contents($this->dir.'/PERMS')));
        $opts = file_get_contents($this->dir.'/OPTS');
        $this->assertStringContainsString('host="db"', $opts);
        $this->assertStringContainsString('user="toko_user"', $opts);
        $this->assertStringContainsString('password="p@ss#\"w\\\\rd"', $opts, 'tanda kutip & backslash di-escape');
        $this->assertFileDoesNotExist(trim(file_get_contents($this->dir.'/OPTPATH')), 'file kredensial harus dihapus');
    }

    public function test_dump_gagal_memberi_pesan_bersih_dan_tetap_menghapus_file_kredensial(): void
    {
        $this->recordingBinary(<<<'SH'
echo "mariadb-dump: Warning: Charset id '33' csname 'utf8' trying to replace existing csname 'utf8mb3'" >&2
echo 'mariadb-dump: Got error: 1045: "Access denied for user" when trying to connect' >&2
exit 2
SH);

        try {
            (new MysqlDumper())->dump($this->dir.'/out.sql');
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Access denied', $e->getMessage());
            $this->assertStringNotContainsString('Charset', $e->getMessage(), 'noise peringatan charset disaring');
            $this->assertStringNotContainsString('p@ss', $e->getMessage());
        }

        $this->assertFileDoesNotExist(trim(file_get_contents($this->dir.'/OPTPATH')));
    }

    public function test_dump_tanpa_penanda_selesai_ditolak(): void
    {
        $this->recordingBinary('echo "INSERT INTO t VALUES (1);" > "$out"');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('tidak lengkap');

        (new MysqlDumper())->dump($this->dir.'/out.sql');
    }

    public function test_penanda_selesai_di_tengah_file_tidak_dianggap_lengkap(): void
    {
        $this->recordingBinary('{ echo "-- Dump completed"; head -c 5000 /dev/zero | tr "\\0" "x"; } > "$out"');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('tidak lengkap');

        (new MysqlDumper())->dump($this->dir.'/out.sql');
    }

    public function test_dump_memakai_unix_socket_dari_config_laravel_bila_ada(): void
    {
        config(['database.connections.mysql.unix_socket' => '/run/mysqld/mysqld.sock']);
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $opts = file_get_contents($this->dir.'/OPTS');
        $this->assertStringContainsString('socket="/run/mysqld/mysqld.sock"', $opts);
        $this->assertStringNotContainsString('host=', $opts);
    }

    public function test_unix_socket_kosong_di_config_berarti_pakai_tcp(): void
    {
        config(['database.connections.mysql.unix_socket' => '']);   // default env('DB_SOCKET', '')
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $opts = file_get_contents($this->dir.'/OPTS');
        $this->assertStringContainsString('host="db"', $opts);
        $this->assertStringNotContainsString('socket=', $opts);
    }

    public function test_driver_selain_mysql_ditolak(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.driver' => 'sqlite']);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('hanya mendukung database MySQL');

        (new MysqlDumper())->dump($this->dir.'/out.sql');
    }

    public function test_tls_dimatikan_secara_default_agar_sertifikat_self_signed_tidak_menggagalkan_backup(): void
    {
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $opts = file_get_contents($this->dir.'/OPTS');
        $this->assertStringContainsString("\nloose-skip-ssl\n", $opts);
        $this->assertStringContainsString("\nloose-ssl-verify-server-cert=0\n", $opts, 'verifikasi sertifikat juga harus dimatikan');
    }

    public function test_tls_dipakai_bila_diminta_lewat_config(): void
    {
        config(['backup.db_ssl' => true]);
        $this->recordingBinary();

        (new MysqlDumper())->dump($this->dir.'/out.sql');

        $opts = file_get_contents($this->dir.'/OPTS');
        $this->assertStringNotContainsString('skip-ssl', $opts);
        $this->assertStringNotContainsString('ssl-verify-server-cert', $opts);
    }

    public function test_pesan_error_tls_ditampilkan_apa_adanya_dan_noise_peringatan_disaring(): void
    {
        $this->recordingBinary(<<<'SH'
echo "mysqldump: [Warning] unknown option '--loose-skip-ssl'." >&2
echo 'mysqldump: Got error: 2026: "TLS/SSL error: self-signed certificate in certificate chain" when trying to connect' >&2
exit 2
SH);

        try {
            (new MysqlDumper())->dump($this->dir.'/out.sql');
            $this->fail('Seharusnya melempar BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('self-signed certificate', $e->getMessage());
            $this->assertStringNotContainsString('unknown option', $e->getMessage());
        }
    }

    public function test_isi_file_opsi_memakai_socket_bila_ada(): void
    {
        $out = MysqlDumper::optionsFileContents(['socket' => '/run/mysqld.sock', 'username' => 'u', 'password' => 'p']);

        $this->assertStringContainsString('socket="/run/mysqld.sock"', $out);
        $this->assertStringNotContainsString('host=', $out);
    }

    public function test_isi_file_opsi_menolak_baris_baru_pada_kredensial(): void
    {
        $this->expectException(BackupException::class);

        MysqlDumper::optionsFileContents(['host' => 'db', 'username' => 'u', 'password' => "abc\npassword=lain"]);
    }
}